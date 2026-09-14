/**
 * Builds the documentation site in `site/dist` from the Markdown in `docs/`.
 *
 * Dependency-free, and deliberately so — see site/markdown.mjs for why. The
 * whole build is a few hundred milliseconds of file reads, which is what lets
 * the pull-request job in .github/workflows/pages.yml run it as a cheap check
 * that nothing is broken before it ships.
 *
 * There is no landing page. The root of the site is the documentation index,
 * because somebody arriving here has already decided to use the package and is
 * looking for how, not for persuasion.
 *
 * Usage:
 *   node site/build.mjs            # → site/dist, served from /
 *   SITE_BASE=/digital-signature/ node site/build.mjs
 *
 * Environment:
 *   SITE_BASE      URL prefix the site is served under. GitHub Pages puts a
 *                  project site at /<repo>/, so every absolute link has to
 *                  carry that prefix or the CSS 404s and the nav goes nowhere.
 *   SITE_REPO_URL  Repository the "view source" links point at.
 */

import { mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { renderMarkdown, escapeHtml, stripInline } from './markdown.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const DOCS = join(ROOT, 'docs');
const DIST = join(ROOT, 'site', 'dist');

const BASE = normaliseBase(process.env.SITE_BASE ?? '/');
const REPO = (process.env.SITE_REPO_URL ?? 'https://github.com/cortejojicoy/digital-signature')
    .replace(/\/+$/, '');

const SITE_NAME = 'Digital Signature for Filament';

/**
 * The sidebar, and the only list of what the site contains.
 *
 * Order is editorial, not alphabetical: it is the order somebody integrating
 * the package actually needs these in. The build fails if a file in docs/ is
 * missing from here, so a new document cannot be quietly left out of the site
 * — a doc nobody can navigate to is a doc nobody reads.
 */
const NAV = [
    {
        title: 'Start here',
        items: [
            { file: 'index.md', label: 'Overview' },
            { file: 'installation.md', label: 'Installation' },
            { file: 'implementation-plan.md', label: 'Implementation plan' },
        ],
    },
    {
        title: 'Set up',
        items: [
            { file: 'configuration.md', label: 'Configuration' },
            { file: 'model-setup.md', label: 'Model setup' },
            { file: 'certificates.md', label: 'Certificates' },
        ],
    },
    {
        title: 'Signing documents',
        items: [
            { file: 'signing-workflow.md', label: 'Signing workflow' },
            { file: 'ad-hoc-signing.md', label: 'Ad-hoc signing' },
            { file: 'on-demand-pdf-signing.md', label: 'On-demand PDF signing' },
            { file: 'pdf-templates.md', label: 'PDF templates' },
            { file: 'signatory-routing.md', label: 'Signatory routing' },
        ],
    },
    {
        title: 'In the panel',
        items: [
            { file: 'filament-components.md', label: 'Filament components' },
            { file: 'drawer-signing-ux-plan.md', label: 'Drawer signing UX' },
        ],
    },
    {
        title: 'Reference',
        items: [
            { file: 'security.md', label: 'Security' },
            { file: 'concepts/signatory-routing.md', label: 'Design: signatory routing' },
        ],
    },
];

// ---------------------------------------------------------------------------

async function build() {
    const started = Date.now();

    const listed = NAV.flatMap((group) => group.items.map((item) => item.file));
    const present = await listMarkdown(DOCS);

    const missing = present.filter((f) => !listed.includes(f));
    const phantom = listed.filter((f) => !present.includes(f));

    if (missing.length > 0) {
        fail(`These documents exist but are not in the sidebar:\n  ${missing.join('\n  ')}\n`
            + 'Add them to NAV in site/build.mjs, or delete them.');
    }
    if (phantom.length > 0) {
        fail(`The sidebar lists documents that do not exist:\n  ${phantom.join('\n  ')}`);
    }

    // Resolve every page first: the renderer needs to know which link targets
    // are real pages before it rewrites any of them.
    const pages = listed.map((file) => ({
        file,
        slug: slugFor(file),
        url: urlFor(file),
        label: labelFor(file),
    }));

    await rm(DIST, { recursive: true, force: true });
    await mkdir(DIST, { recursive: true });

    const index = [];

    for (const page of pages) {
        const source = await readFile(join(DOCS, page.file), 'utf8');
        const { html, headings, title } = renderMarkdown(source, {
            rewriteLink: (href) => rewriteLink(href, page, pages),
        });

        const heading = title ?? page.label;
        const outPath = page.slug === ''
            ? join(DIST, 'index.html')
            : join(DIST, page.slug, 'index.html');

        await mkdir(dirname(outPath), { recursive: true });
        await writeFile(outPath, layout({ page, heading, html, headings }), 'utf8');

        index.push({
            title: heading,
            label: page.label,
            url: page.url,
            headings: headings.filter((h) => h.depth > 1).map((h) => ({ text: h.text, id: h.id })),
            text: searchableText(source),
        });
    }

    await verifyLinks(pages);

    await mkdir(join(DIST, 'assets'), { recursive: true });
    await writeFile(join(DIST, 'assets', 'site.css'), CSS, 'utf8');
    await writeFile(join(DIST, 'assets', 'site.js'), CLIENT, 'utf8');
    await writeFile(join(DIST, 'search.json'), JSON.stringify(index), 'utf8');

    // GitHub Pages runs Jekyll over the artifact unless told not to, which
    // strips files and directories beginning with an underscore.
    await writeFile(join(DIST, '.nojekyll'), '', 'utf8');
    await writeFile(join(DIST, '404.html'), notFound(), 'utf8');

    console.log(`site: ${pages.length} pages → site/dist in ${Date.now() - started}ms (base ${BASE})`);
}

/**
 * Every internal link, and every in-page anchor, resolves to something.
 *
 * This is the reason the pull-request job in pages.yml builds the site at all.
 * A cross-reference that rotted when a heading was reworded is invisible in a
 * diff and obvious to a reader, which is the wrong way round; failing the
 * build turns it back into something the author sees first.
 */
async function verifyLinks(pages) {
    const anchors = new Map();
    const problems = [];

    for (const page of pages) {
        const file = page.slug === ''
            ? join(DIST, 'index.html')
            : join(DIST, page.slug, 'index.html');

        // Every id on the rendered page, not just the headings: the layout
        // contributes its own targets (the skip link aims at #content), and a
        // checker that only knew about headings would report those as broken.
        const html = await readFile(file, 'utf8');
        anchors.set(page.url, new Set([...html.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1])));
    }

    for (const page of pages) {
        const file = page.slug === ''
            ? join(DIST, 'index.html')
            : join(DIST, page.slug, 'index.html');
        const html = await readFile(file, 'utf8');

        for (const href of [...html.matchAll(/href="([^"]+)"/g)].map((m) => m[1])) {
            if (/^(https?:|mailto:)/i.test(href)) continue;

            const [path, hash] = splitFragment(href);
            const fragment = hash.slice(1);

            // An in-page anchor: check it against this page's own headings.
            if (path === '') {
                if (fragment && !anchors.get(page.url)?.has(fragment)) {
                    problems.push(`${page.file} → #${fragment} (no such heading on this page)`);
                }
                continue;
            }

            if (path.startsWith(`${BASE}assets/`) || path === `${BASE}search.json`) continue;

            const target = pages.find((p) => p.url === path);

            if (!target) {
                problems.push(`${page.file} → ${href} (no such page)`);
                continue;
            }

            if (fragment && !anchors.get(target.url)?.has(fragment)) {
                problems.push(`${page.file} → ${href} (no such heading in ${target.file})`);
            }
        }
    }

    if (problems.length > 0) {
        fail(`${problems.length} broken link(s):\n  ${problems.join('\n  ')}`);
    }
}

// -- Paths and links ---------------------------------------------------------

function normaliseBase(value) {
    const trimmed = String(value).trim();
    if (trimmed === '' || trimmed === '/') return '/';

    return `/${trimmed.replace(/^\/+|\/+$/g, '')}/`;
}

/** `index.md` is the site root; everything else becomes a directory. */
function slugFor(file) {
    return file === 'index.md' ? '' : file.replace(/\.md$/, '');
}

function urlFor(file) {
    const slug = slugFor(file);

    return slug === '' ? BASE : `${BASE}${slug}/`;
}

function labelFor(file) {
    for (const group of NAV) {
        for (const item of group.items) {
            if (item.file === file) return item.label;
        }
    }

    return file;
}

/**
 * Turn a link written for GitHub's Markdown view into one that works here.
 *
 * The docs are read in both places — in the repository and on this site — so
 * the source keeps GitHub's relative form and this translates it, rather than
 * making the Markdown carry site-specific URLs that would be wrong on GitHub.
 */
function rewriteLink(href, page, pages) {
    if (/^(https?:|mailto:|#)/i.test(href)) return href;

    const [path, fragment] = splitFragment(href);

    // A link out of docs/ is a link into the source tree.
    if (path.startsWith('../')) {
        return `${REPO}/blob/main/${path.replace(/^(\.\.\/)+/, '')}${fragment}`;
    }

    if (path.endsWith('.md')) {
        const target = resolveDocPath(path, page.file);
        const match = pages.find((p) => p.file === target);

        if (match) return `${match.url}${fragment}`;

        // A real file that the sidebar does not list cannot happen — the build
        // refuses that above — so this is a link to something that was renamed
        // or deleted. Point at the repository rather than emitting a dead
        // internal URL that looks like it should work.
        return `${REPO}/blob/main/docs/${target}${fragment}`;
    }

    return href;
}

function splitFragment(href) {
    const hash = href.indexOf('#');

    return hash === -1 ? [href, ''] : [href.slice(0, hash), href.slice(hash)];
}

/** Resolve a relative doc link against the document that contains it. */
function resolveDocPath(path, fromFile) {
    const fromDir = fromFile.includes('/') ? fromFile.slice(0, fromFile.lastIndexOf('/')) : '';
    const segments = (fromDir ? `${fromDir}/${path}` : path).split('/');
    const stack = [];

    for (const segment of segments) {
        if (segment === '.' || segment === '') continue;
        if (segment === '..') stack.pop();
        else stack.push(segment);
    }

    return stack.join('/');
}

async function listMarkdown(dir, prefix = '') {
    const entries = await readdir(dir, { withFileTypes: true });
    const files = [];

    for (const entry of entries) {
        const relative = prefix ? `${prefix}/${entry.name}` : entry.name;

        if (entry.isDirectory()) files.push(...await listMarkdown(join(dir, entry.name), relative));
        else if (entry.name.endsWith('.md')) files.push(relative);
    }

    return files.sort();
}

/** Prose only: code blocks and markup would dominate a search index. */
function searchableText(source) {
    return stripInline(
        source
            .replace(/```[\s\S]*?```/g, ' ')
            .replace(/^\|.*$/gm, ' ')
            .replace(/[#>*_`]/g, ' '),
    ).replace(/\s+/g, ' ').slice(0, 4000);
}

// -- Templates ---------------------------------------------------------------

function layout({ page, heading, html, headings }) {
    const onThisPage = headings.filter((h) => h.depth === 2);

    return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escapeHtml(pageTitle(heading))}</title>
<meta name="description" content="${escapeHtml(`${heading} — documentation for ${SITE_NAME}.`)}">
<link rel="stylesheet" href="${BASE}assets/site.css">
</head>
<body>
<a class="skip" href="#content">Skip to content</a>

<header class="topbar">
  <a class="brand" href="${BASE}">${escapeHtml(SITE_NAME)}</a>
  <div class="search">
    <input id="q" type="search" placeholder="Search the docs" autocomplete="off"
           aria-label="Search the documentation">
    <div id="results" class="results" hidden></div>
  </div>
  <a class="repo" href="${REPO}" target="_blank" rel="noopener">GitHub</a>
  <button class="menu" id="menu" aria-label="Toggle navigation">☰</button>
</header>

<div class="shell">
  <nav class="sidebar" id="sidebar" aria-label="Documentation">
    ${NAV.map((group) => `<section>
      <h2>${escapeHtml(group.title)}</h2>
      <ul>${group.items.map((item) => {
        const url = urlFor(item.file);
        const current = item.file === page.file;
        return `<li><a href="${url}"${current ? ' aria-current="page"' : ''}>${escapeHtml(item.label)}</a></li>`;
    }).join('')}</ul>
    </section>`).join('')}
  </nav>

  <main id="content">
    <article class="prose">${html}</article>
    <footer class="pagefoot">
      <a href="${REPO}/blob/main/docs/${page.file}" target="_blank" rel="noopener">Edit this page on GitHub</a>
    </footer>
  </main>

  ${onThisPage.length > 1 ? `<aside class="toc" aria-label="On this page">
    <h2>On this page</h2>
    <ul>${onThisPage.map((h) => `<li><a href="#${h.id}">${escapeHtml(h.text)}</a></li>`).join('')}</ul>
  </aside>` : '<aside class="toc"></aside>'}
</div>

<script>window.SITE_BASE=${JSON.stringify(BASE)};</script>
<script src="${BASE}assets/site.js" defer></script>
</body>
</html>
`;
}

/**
 * The browser-tab title.
 *
 * Suffixed with the site name so a tab is identifiable among a dozen others —
 * except where the heading already contains it, which is the index page, where
 * the suffix would just say the same thing twice.
 */
function pageTitle(heading) {
    return heading.includes(SITE_NAME) ? heading : `${heading} · ${SITE_NAME}`;
}

function notFound() {
    return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Not found · ${escapeHtml(SITE_NAME)}</title>
<link rel="stylesheet" href="${BASE}assets/site.css">
</head>
<body>
<header class="topbar"><a class="brand" href="${BASE}">${escapeHtml(SITE_NAME)}</a></header>
<div class="shell"><main id="content"><article class="prose">
<h1>Not found</h1>
<p>That page does not exist. Start from the <a href="${BASE}">documentation index</a>.</p>
</article></main></div>
</body>
</html>
`;
}

// -- Assets ------------------------------------------------------------------

const CSS = `:root {
  color-scheme: light dark;
  --bg: #ffffff;
  --fg: #18181b;
  --muted: #5b5b66;
  --line: rgba(0,0,0,.10);
  --accent: #0d9488;
  --code-bg: #f4f4f5;
  --sidebar-bg: #fafafa;
  --max: 46rem;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #0b0b0e; --fg: #ececf1; --muted: #a1a1aa; --line: rgba(255,255,255,.12);
    --accent: #2dd4bf; --code-bg: #17171c; --sidebar-bg: #101015;
  }
}
* { box-sizing: border-box; }
html { scroll-behavior: smooth; scroll-padding-top: 4.5rem; }
body {
  margin: 0; background: var(--bg); color: var(--fg);
  font: 16px/1.65 system-ui, -apple-system, "Segoe UI", sans-serif;
  -webkit-text-size-adjust: 100%;
}
a { color: var(--accent); }
.skip {
  position: absolute; left: -9999px; top: 0; padding: .6rem 1rem;
  background: var(--bg); z-index: 100;
}
.skip:focus { left: 0; }

/* -- Top bar -- */
.topbar {
  position: sticky; top: 0; z-index: 30;
  display: flex; align-items: center; gap: 1rem;
  padding: .7rem 1.25rem; background: var(--bg);
  border-bottom: 1px solid var(--line);
}
.brand { font-weight: 700; text-decoration: none; color: inherit; font-size: .95rem; }
.repo { font-size: .85rem; text-decoration: none; }
.menu {
  display: none; border: 1px solid var(--line); background: transparent; color: inherit;
  border-radius: .45rem; padding: .25rem .5rem; cursor: pointer; font-size: 1rem;
}

/* -- Search -- */
.search { position: relative; margin-left: auto; width: min(22rem, 45vw); }
#q {
  width: 100%; padding: .4rem .7rem; border-radius: .5rem; font: inherit; font-size: .875rem;
  border: 1px solid var(--line); background: var(--code-bg); color: inherit;
}
.results {
  position: absolute; top: calc(100% + .4rem); left: 0; right: 0; max-height: 65vh;
  overflow-y: auto; background: var(--bg); border: 1px solid var(--line);
  border-radius: .6rem; box-shadow: 0 12px 28px -12px rgba(0,0,0,.35); padding: .35rem;
}
.results a {
  display: block; padding: .45rem .6rem; border-radius: .4rem;
  text-decoration: none; color: inherit;
}
.results a:hover, .results a:focus { background: var(--code-bg); }
.results .where { display: block; font-size: .75rem; color: var(--muted); }
.results .none { padding: .6rem; color: var(--muted); font-size: .875rem; }

/* -- Layout -- */
.shell {
  display: grid; grid-template-columns: 16rem minmax(0, 1fr) 14rem;
  gap: 2.5rem; max-width: 84rem; margin: 0 auto; padding: 2rem 1.25rem 5rem;
  align-items: start;
}
.sidebar { position: sticky; top: 4.25rem; max-height: calc(100vh - 5.5rem); overflow-y: auto; }
.sidebar section + section { margin-top: 1.4rem; }
.sidebar h2 {
  font-size: .72rem; text-transform: uppercase; letter-spacing: .06em;
  color: var(--muted); margin: 0 0 .45rem;
}
.sidebar ul { list-style: none; margin: 0; padding: 0; }
.sidebar a {
  display: block; padding: .28rem .6rem; border-radius: .4rem;
  text-decoration: none; color: inherit; font-size: .9rem;
  border-left: 2px solid transparent;
}
.sidebar a:hover { background: var(--sidebar-bg); }
.sidebar a[aria-current="page"] {
  color: var(--accent); border-left-color: var(--accent); font-weight: 600;
}

.toc { position: sticky; top: 4.25rem; max-height: calc(100vh - 5.5rem); overflow-y: auto; }
.toc h2 {
  font-size: .72rem; text-transform: uppercase; letter-spacing: .06em;
  color: var(--muted); margin: 0 0 .45rem;
}
.toc ul { list-style: none; margin: 0; padding: 0; }
.toc a {
  display: block; padding: .2rem 0; font-size: .82rem; color: var(--muted);
  text-decoration: none; overflow-wrap: anywhere;
}
.toc a:hover { color: var(--accent); }

/* -- Prose -- */
.prose { max-width: var(--max); }
.prose h1 { font-size: 1.9rem; line-height: 1.2; margin: 0 0 1rem; letter-spacing: -.02em; }
.prose h2 {
  font-size: 1.3rem; margin: 2.4rem 0 .8rem; padding-top: .9rem;
  border-top: 1px solid var(--line); letter-spacing: -.01em;
}
.prose h3 { font-size: 1.05rem; margin: 1.8rem 0 .6rem; }
.prose h4 { font-size: .95rem; margin: 1.4rem 0 .5rem; color: var(--muted); }
.prose p { margin: 0 0 1rem; }
.prose ul, .prose ol { margin: 0 0 1rem; padding-left: 1.3rem; }
.prose li { margin: .25rem 0; }
.prose li > ul, .prose li > ol { margin: .25rem 0 .25rem; }
.prose hr { border: 0; border-top: 1px solid var(--line); margin: 2rem 0; }
.prose blockquote {
  margin: 0 0 1rem; padding: .1rem 1rem; border-left: 3px solid var(--accent);
  color: var(--muted);
}
.prose blockquote p:last-child { margin-bottom: 0; }
.prose code {
  background: var(--code-bg); padding: .12em .35em; border-radius: .3rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .875em;
}
.prose pre {
  background: var(--code-bg); border: 1px solid var(--line); border-radius: .6rem;
  padding: .9rem 1rem; overflow-x: auto; margin: 0 0 1.2rem;
}
.prose pre code { background: none; padding: 0; font-size: .82rem; line-height: 1.6; }
.table-scroll { overflow-x: auto; margin: 0 0 1.2rem; }
.prose table { border-collapse: collapse; width: 100%; font-size: .875rem; }
.prose th, .prose td {
  border: 1px solid var(--line); padding: .45rem .65rem; text-align: left; vertical-align: top;
}
.prose th { background: var(--code-bg); font-weight: 600; }
.prose a.anchor {
  float: left; margin-left: -1rem; width: 1rem; opacity: 0;
  text-decoration: none; color: var(--muted); font-weight: 400;
}
.prose h1:hover .anchor, .prose h2:hover .anchor,
.prose h3:hover .anchor, .prose h4:hover .anchor { opacity: 1; }

.pagefoot {
  margin-top: 3rem; padding-top: 1rem; border-top: 1px solid var(--line);
  font-size: .85rem;
}

@media (max-width: 68rem) {
  .shell { grid-template-columns: 15rem minmax(0, 1fr); }
  .toc { display: none; }
}
@media (max-width: 52rem) {
  .menu { display: block; }
  .search { width: auto; flex: 1; }
  .repo { display: none; }
  .shell { grid-template-columns: minmax(0, 1fr); padding-top: 1.25rem; }
  .sidebar {
    position: static; max-height: none; display: none;
    padding-bottom: 1.25rem; margin-bottom: 1.25rem; border-bottom: 1px solid var(--line);
  }
  .sidebar.open { display: block; }
}
`;

const CLIENT = `/**
 * Search, and the small-screen navigation toggle.
 *
 * The index is one JSON file fetched on first keystroke rather than with the
 * page: most readers never search, and making every page carry the text of
 * every other page would be the largest thing on the site by far.
 */
(function () {
  var base = window.SITE_BASE || '/';
  var input = document.getElementById('q');
  var panel = document.getElementById('results');
  var menu = document.getElementById('menu');
  var sidebar = document.getElementById('sidebar');
  var index = null;
  var loading = null;

  if (menu && sidebar) {
    menu.addEventListener('click', function () { sidebar.classList.toggle('open'); });
  }

  if (!input || !panel) return;

  function load() {
    if (index) return Promise.resolve(index);
    if (!loading) {
      loading = fetch(base + 'search.json')
        .then(function (r) { return r.json(); })
        .then(function (data) { index = data; return data; })
        .catch(function () { return []; });
    }
    return loading;
  }

  function escape(text) {
    return String(text).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function search(query) {
    var needle = query.toLowerCase();
    var hits = [];

    index.forEach(function (page) {
      if (page.title.toLowerCase().indexOf(needle) !== -1
          || page.label.toLowerCase().indexOf(needle) !== -1) {
        hits.push({ url: page.url, title: page.title, where: 'Page', rank: 0 });
      }

      page.headings.forEach(function (h) {
        if (h.text.toLowerCase().indexOf(needle) !== -1) {
          hits.push({ url: page.url + '#' + h.id, title: h.text, where: page.title, rank: 1 });
        }
      });

      if (hits.length < 30 && page.text.toLowerCase().indexOf(needle) !== -1) {
        hits.push({ url: page.url, title: page.title, where: 'Mentioned in the text', rank: 2 });
      }
    });

    return hits.sort(function (a, b) { return a.rank - b.rank; }).slice(0, 12);
  }

  function render(hits) {
    if (hits.length === 0) {
      panel.innerHTML = '<p class="none">Nothing matched.</p>';
    } else {
      panel.innerHTML = hits.map(function (h) {
        return '<a href="' + h.url + '">' + escape(h.title)
          + '<span class="where">' + escape(h.where) + '</span></a>';
      }).join('');
    }
    panel.hidden = false;
  }

  input.addEventListener('input', function () {
    var query = input.value.trim();

    if (query.length < 2) { panel.hidden = true; return; }

    load().then(function () { render(search(query)); });
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { panel.hidden = true; input.blur(); }
  });

  document.addEventListener('click', function (e) {
    if (!panel.contains(e.target) && e.target !== input) panel.hidden = true;
  });

  // "/" focuses search, the convention every docs site shares.
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && document.activeElement !== input) {
      e.preventDefault();
      input.focus();
    }
  });
})();
`;

function fail(message) {
    console.error(`site: ${message}`);
    process.exit(1);
}

await build();
