/**
 * A small Markdown renderer, covering exactly what this package's docs use.
 *
 * Dependency-free on purpose. The docs are the one part of this repository a
 * reader reaches without installing anything, and a build that pulls a tree of
 * packages to render them is a build that rots: the site stops deploying one
 * day because something three levels down published a breaking major.
 *
 * The supported subset is not a guess — it was measured against the real
 * files: ATX headings, fenced code with a language, pipe tables, blockquotes,
 * thematic breaks, ordered and unordered lists with one level of nesting, and
 * the inline set (code, links, bold, italic). There are no images and
 * essentially no raw HTML in these documents. Anything outside that subset
 * renders as literal text rather than silently disappearing, which is the
 * failure mode you can actually see and fix.
 */

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

export function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (c) => ESCAPES[c]);
}

/**
 * GitHub's heading anchors, exactly — including the parts that look wrong.
 *
 * GitHub lowercases, drops punctuation, then replaces each space with a
 * hyphen. It does NOT collapse the runs that leaves behind, so a heading like
 * "Phase 0 — Prerequisites" anchors at `phase-0--prerequisites` with two
 * hyphens, the em-dash having been deleted from between two spaces.
 *
 * Collapsing them reads tidier and breaks every table of contents already
 * written in these documents, which were authored against GitHub's rendering.
 * Matching the quirk is the whole job.
 */
export function slugify(text) {
    return String(text)
        .toLowerCase()
        .replace(/`/g, '')
        .replace(/[^\w\s-]/g, '')
        .trim()
        .replace(/\s/g, '-');
}

// Parks code spans while the emphasis and link rules run over everything else.
// A NUL rather than anything printable, because the placeholder has to be
// something the source genuinely cannot contain — a visible sentinel would
// eventually turn up inside somebody's code sample and be substituted back out
// as a stray fragment of an unrelated line. Written as an escape so the file
// stays plain ASCII for editors and diffs.
const MARK = '\u0000';

/**
 * @param {string} source   Markdown.
 * @param {object} options  { rewriteLink(href) => href }
 * @returns {{ html: string, headings: Array<{depth:number,text:string,id:string}>, title: string|null }}
 */
export function renderMarkdown(source, options = {}) {
    const rewrite = options.rewriteLink ?? ((href) => href);
    // Injected rather than imported, so this module stays a plain Markdown
    // renderer that can be tested without the site's code chrome.
    const renderCode = options.renderCode ?? defaultCodeBlock;
    const lines = String(source).replace(/\r\n?/g, '\n').split('\n');

    const out = [];
    const headings = [];
    let title = null;
    let i = 0;

    const inline = (text) => renderInline(text, rewrite);

    while (i < lines.length) {
        const line = lines[i];

        // -- Fenced code --------------------------------------------------
        const fence = line.match(/^```([\w-]*)\s*$/);
        if (fence) {
            const lang = fence[1];
            const body = [];
            i++;
            while (i < lines.length && !/^```\s*$/.test(lines[i])) body.push(lines[i++]);
            i++;   // closing fence
            out.push(renderCode(body.join('\n'), lang));
            continue;
        }

        // -- Heading ------------------------------------------------------
        const heading = line.match(/^(#{1,6})\s+(.*)$/);
        if (heading) {
            const depth = heading[1].length;
            const raw = heading[2].trim().replace(/\s+#*$/, '');
            const id = slugify(raw);
            headings.push({ depth, text: stripInline(raw), id });
            if (depth === 1 && title === null) title = stripInline(raw);
            out.push(
                `<h${depth} id="${id}">`
                + `<a class="anchor" href="#${id}" aria-label="Link to this section">#</a>`
                + `${inline(raw)}</h${depth}>`,
            );
            i++;
            continue;
        }

        // -- Table --------------------------------------------------------
        // A header row followed by a delimiter row. Checked before thematic
        // breaks so a |---|---| delimiter is never mistaken for one.
        if (line.startsWith('|') && /^\s*\|?[\s:|-]+\|[\s:|-]*$/.test(lines[i + 1] ?? '')) {
            const header = splitRow(line);
            const aligns = splitRow(lines[i + 1]).map(alignOf);
            i += 2;

            const rows = [];
            while (i < lines.length && lines[i].trim().startsWith('|')) rows.push(splitRow(lines[i++]));

            out.push(
                '<div class="table-scroll"><table><thead><tr>'
                + header.map((c, n) => `<th${styleOf(aligns[n])}>${inline(c)}</th>`).join('')
                + '</tr></thead><tbody>'
                + rows.map((r) => '<tr>'
                    + r.map((c, n) => `<td${styleOf(aligns[n])}>${inline(c)}</td>`).join('')
                    + '</tr>').join('')
                + '</tbody></table></div>',
            );
            continue;
        }

        // -- Thematic break -----------------------------------------------
        if (/^\s*(-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
            out.push('<hr>');
            i++;
            continue;
        }

        // -- Blockquote ---------------------------------------------------
        if (/^>\s?/.test(line)) {
            const body = [];
            while (i < lines.length && /^>\s?/.test(lines[i])) body.push(lines[i++].replace(/^>\s?/, ''));
            out.push(`<blockquote>${renderMarkdown(body.join('\n'), options).html}</blockquote>`);
            continue;
        }

        // -- Lists --------------------------------------------------------
        if (isListItem(line)) {
            const [html, next] = renderList(lines, i, options, inline);
            out.push(html);
            i = next;
            continue;
        }

        // -- Blank --------------------------------------------------------
        if (line.trim() === '') {
            i++;
            continue;
        }

        // -- Paragraph ----------------------------------------------------
        const para = [];
        while (
            i < lines.length
            && lines[i].trim() !== ''
            && !/^```/.test(lines[i])
            && !/^#{1,6}\s/.test(lines[i])
            && !/^>\s?/.test(lines[i])
            && !isListItem(lines[i])
            && !/^\s*(-{3,}|\*{3,}|_{3,})\s*$/.test(lines[i])
            && !lines[i].startsWith('|')
        ) {
            para.push(lines[i++]);
        }
        out.push(`<p>${inline(para.join('\n'))}</p>`);
    }

    return { html: out.join('\n'), headings, title };
}

function defaultCodeBlock(code, lang) {
    const cls = lang ? ` class="language-${escapeHtml(lang)}"` : '';

    return `<pre><code${cls}>${escapeHtml(code)}</code></pre>`;
}

// -- Lists -------------------------------------------------------------------

const LIST_ITEM = /^(\s*)([-*+]|\d+\.)\s+(.*)$/;

function isListItem(line) {
    return LIST_ITEM.test(line ?? '');
}

/**
 * One list, and everything belonging to its items.
 *
 * An item is not just its first line. Anything indented past the marker is
 * part of it — a nested list, a fenced block, a second paragraph, a
 * blockquote — and that content is rendered as blocks rather than flattened
 * into the item's text.
 *
 * The flattening is what this replaced. An indented quote inside a numbered
 * step came out as one run-on paragraph with literal ">" characters in it,
 * which is exactly the kind of thing nobody notices in a diff and everybody
 * notices on the page.
 */
function renderList(lines, start, options, inline) {
    const first = lines[start].match(LIST_ITEM);
    const baseIndent = first[1].length;
    const ordered = /\d/.test(first[2]);

    const items = [];
    let i = start;

    while (i < lines.length) {
        const line = lines[i];
        const match = line.match(LIST_ITEM);

        // A sibling item. A change of kind — bullets to numbers — ends the
        // list rather than continuing it in the wrong element.
        if (match && match[1].length === baseIndent) {
            if (/\d/.test(match[2]) !== ordered) break;
            items.push([match[3]]);
            i++;
            continue;
        }

        if (items.length === 0) break;

        // A blank line belongs to the item only when indented content follows
        // it. Otherwise it is the end of the list.
        if (line.trim() === '') {
            const next = lines[i + 1];
            const continues = next !== undefined
                && (/^\s{2,}\S/.test(next)
                    || (next.match(LIST_ITEM)?.[1].length ?? -1) === baseIndent);

            if (!continues) break;

            items[items.length - 1].push('');
            i++;
            continue;
        }

        if (/^\s{2,}\S/.test(line)) {
            items[items.length - 1].push(line);
            i++;
            continue;
        }

        break;
    }

    const tag = ordered ? 'ol' : 'ul';
    const body = items.map((raw) => `<li>${renderItem(raw, options, inline)}</li>`).join('');

    return [`<${tag}>${body}</${tag}>`, i];
}

/**
 * One item's contents.
 *
 * Prose that merely wrapped stays inline, so an ordinary list renders tight.
 * An item carrying actual blocks goes through the full renderer, which is what
 * makes a quote or a code sample inside a step work.
 */
function renderItem(raw, options, inline) {
    if (raw.length === 1) return inline(raw[0]);

    const rest = raw.slice(1);
    const indents = rest.filter((l) => l.trim() !== '').map((l) => l.match(/^\s*/)[0].length);
    const dedent = indents.length > 0 ? Math.min(...indents) : 0;
    const tail = rest.map((l) => l.slice(dedent));

    const hasBlocks = tail.some((l) => /^(>|```|[-*+]\s|\d+\.\s)/.test(l))
        || tail.some((l, n) => l.trim() === '' && tail.slice(n + 1).some((m) => m.trim() !== ''));

    if (!hasBlocks) {
        return inline([raw[0], ...tail.map((l) => l.trim())].join(' ').trim());
    }

    return renderMarkdown([raw[0], ...tail].join('\n'), options).html;
}

// -- Inline ------------------------------------------------------------------

/**
 * Code spans are lifted out before anything else runs and put back last, so
 * `**not bold**` inside backticks stays literal — which matters in documents
 * that quote Markdown and PHP at each other constantly.
 */
function renderInline(text, rewrite) {
    const codes = [];
    let work = String(text).replace(/`([^`]+)`/g, (_, code) => {
        codes.push(code);
        return `${MARK}${codes.length - 1}${MARK}`;
    });

    work = escapeHtml(work);

    // Links before emphasis: a URL can contain underscores and asterisks.
    work = work.replace(
        /\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/g,
        (_, label, href, titleAttr) => {
            const resolved = rewrite(href);
            const external = /^https?:/i.test(resolved);
            const attrs = [
                `href="${resolved}"`,
                titleAttr ? `title="${titleAttr}"` : '',
                external ? 'target="_blank" rel="noopener"' : '',
            ].filter(Boolean).join(' ');
            return `<a ${attrs}>${label}</a>`;
        },
    );

    work = work.replace(
        /(^|[\s(])(https?:\/\/[^\s<)]+)/g,
        (_, pre, url) => `${pre}<a href="${url}" target="_blank" rel="noopener">${url}</a>`,
    );

    work = work.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    work = work.replace(/(^|[^*\w])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');

    const restore = new RegExp(`${MARK}(\\d+)${MARK}`, 'g');

    return work.replace(restore, (_, n) => `<code>${escapeHtml(codes[Number(n)])}</code>`);
}

/** Heading text with its markup removed, for titles, nav and the search index. */
export function stripInline(text) {
    return String(text)
        .replace(/`([^`]+)`/g, '$1')
        .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replace(/\*\*([^*]+)\*\*/g, '$1')
        .replace(/\*([^*]+)\*/g, '$1')
        .trim();
}

// -- Table helpers -----------------------------------------------------------

function splitRow(line) {
    return line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map((c) => c.trim());
}

function alignOf(cell) {
    const left = cell.startsWith(':');
    const right = cell.endsWith(':');
    if (left && right) return 'center';
    if (right) return 'right';
    return null;
}

function styleOf(align) {
    return align ? ` style="text-align:${align}"` : '';
}
