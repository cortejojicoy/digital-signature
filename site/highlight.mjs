/**
 * Syntax highlighting and code chrome, dependency-free.
 *
 * Two frames, because the docs contain two different kinds of block and they
 * ask different things of a reader:
 *
 *   editor    PHP, Blade and untagged blocks — code you are going to paste
 *             into a file. Gets a gutter of line numbers so a reader and a
 *             reviewer can point at the same line.
 *
 *   terminal  Shell blocks — things you type, or `.env` files you edit. Gets a
 *             window frame and, per line, a prompt.
 *
 * The highlighter is a tokenizer rather than a set of blind replacements: one
 * ordered alternation is matched against the raw source, and anything it does
 * not recognise is escaped and passed through. That ordering is what stops a
 * keyword inside a string from being coloured as a keyword, which is the usual
 * way a regex-based highlighter embarrasses itself.
 *
 * Everything is escaped on the way out, token or not, so a code sample can
 * never inject markup into the page.
 */

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

function esc(text) {
    return String(text).replace(/[&<>"']/g, (c) => ESCAPES[c]);
}

// -- Grammars ----------------------------------------------------------------
//
// Order is the grammar. Comments and strings come first so their contents are
// never re-examined; the catch-all identifier rules come last.

const PHP = [
    ['comment', /\/\*[\s\S]*?\*\/|\/\/[^\n]*/],
    // A `#` starts a comment, unless it opens an attribute — #[Attribute] is
    // PHP 8 syntax and colouring it as a comment would grey out real code.
    ['attribute', /#\[[^\]]*\]/],
    ['comment', /#[^\n]*/],
    ['string', /'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"/],
    ['tag', /<\?php|<\?=|\?>/],
    ['variable', /\$[A-Za-z_]\w*/],
    ['keyword', new RegExp(
        '\\b(?:abstract|array|as|break|callable|case|catch|class|clone|const|continue|declare|'
        + 'default|do|echo|else|elseif|enum|extends|final|finally|fn|for|foreach|function|global|'
        + 'if|implements|include|include_once|instanceof|insteadof|interface|list|match|namespace|'
        + 'new|print|private|protected|public|readonly|require|require_once|return|static|switch|'
        + 'throw|trait|try|use|var|while|yield|true|false|null|int|float|string|bool|void|self|'
        + 'parent|this)\\b', 'i',
    )],
    ['number', /\b\d[\d_]*(?:\.\d+)?\b/],
    ['function', /\b[A-Za-z_]\w*(?=\s*\()/],
    ['type', /\b[A-Z]\w*\b/],
    ['operator', /=>|->|::|\?\?|[+\-*/%.=<>!&|?:]/],
];

const BLADE = [
    ['comment', /\{\{--[\s\S]*?--\}\}/],
    ['directive', /@[A-Za-z]\w*/],
    ['interpolation', /\{\{[\s\S]*?\}\}|\{!![\s\S]*?!!\}/],
    ['string', /'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"/],
    ['tag', /<\/?[A-Za-z][\w.:-]*|\/?>/],
    ['attr', /\b[\w:.-]+(?==)/],
    ['variable', /\$[A-Za-z_]\w*/],
];

const SHELL = [
    ['comment', /#[^\n]*/],
    ['string', /'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"/],
    // An environment assignment: the KEY half is the name, so colour it as one.
    ['envkey', /^[A-Z_][A-Z0-9_]*(?==)/m],
    ['variable', /\$\{?[A-Za-z_]\w*\}?/],
    ['flag', /(?<=\s)--?[A-Za-z][\w-]*/],
    ['builtin', new RegExp(
        '\\b(?:composer|php|artisan|npm|node|git|curl|openssl|cd|ls|cp|mv|rm|mkdir|chmod|chown|'
        + 'echo|export|grep|sed|awk|cat|sudo|docker)\\b',
    )],
    ['number', /\b\d[\d_]*(?:\.\d+)?\b/],
    ['operator', /\|\||&&|[|>&]/],
];

const GRAMMARS = { php: PHP, blade: BLADE, bash: SHELL, sh: SHELL, shell: SHELL, zsh: SHELL };

/** How a language is labelled in the frame's title bar. */
const LABELS = {
    php: 'PHP',
    blade: 'Blade',
    bash: 'Terminal',
    sh: 'Terminal',
    shell: 'Terminal',
    zsh: 'Terminal',
};

// -- Tokenizer ---------------------------------------------------------------

/**
 * @param {string} code  Raw source.
 * @param {string} lang  Language tag from the fence.
 * @returns {string} HTML, fully escaped.
 */
export function highlight(code, lang) {
    const grammar = GRAMMARS[lang];

    if (!grammar) return esc(code);

    // One sticky alternation, tried in order at each position. A named group
    // per rule tells us which matched without re-running any of them.
    const pattern = new RegExp(
        grammar.map(([name, re], i) => `(?<g${i}>${re.source})`).join('|'),
        'gm' + (grammar.some(([, re]) => re.flags.includes('i')) ? 'i' : ''),
    );

    let out = '';
    let last = 0;

    for (const match of code.matchAll(pattern)) {
        const index = match.index;

        // Anything the grammar skipped over is ordinary text.
        if (index > last) out += esc(code.slice(last, index));

        const which = Object.entries(match.groups ?? {}).find(([, v]) => v !== undefined);
        const name = which ? grammar[Number(which[0].slice(1))][0] : 'plain';

        out += `<span class="t-${name}">${esc(match[0])}</span>`;
        last = index + match[0].length;
    }

    return out + esc(code.slice(last));
}

// -- Frames ------------------------------------------------------------------

/**
 * Render one fenced block as a framed figure.
 *
 * The raw source is kept in a data attribute for the copy button, so what a
 * reader copies is what the author wrote — not the line numbers, and not the
 * prompts, neither of which exist as text.
 */
export function renderCodeBlock(code, lang) {
    const language = (lang || '').toLowerCase();
    const terminal = language in GRAMMARS && GRAMMARS[language] === SHELL;
    const body = highlight(code, language);
    const raw = esc(code);

    if (terminal) {
        return `<figure class="code code--terminal" data-code="${raw}">
<figcaption class="code__bar"><span class="code__dots" aria-hidden="true"><i></i><i></i><i></i></span><span class="code__name">${
    esc(LABELS[language] ?? 'Terminal')}</span><button class="code__copy" type="button">Copy</button></figcaption>
<pre class="code__pre"><code>${terminalLines(code, body)}</code></pre>
</figure>`;
    }

    const lines = code.split('\n');
    const gutter = lines.map((_, i) => i + 1).join('\n');
    const label = LABELS[language] ?? (language ? language.toUpperCase() : 'Text');

    return `<figure class="code code--editor" data-code="${raw}">
<figcaption class="code__bar"><span class="code__name">${esc(label)}</span><button class="code__copy" type="button">Copy</button></figcaption>
<div class="code__body"><pre class="code__gutter" aria-hidden="true">${gutter}</pre><pre class="code__pre"><code>${body}</code></pre></div>
</figure>`;
}

/**
 * Wrap each shell line so CSS can put a prompt in front of it.
 *
 * Which lines get one is decided per line, not per block: several blocks in
 * these docs are `.env` files tagged as shell, and printing `$` in front of
 * `SIGNATURE_DISK=local` would tell the reader to run a config file. Comments
 * and blank lines get no prompt either.
 *
 * Safe to split on newlines here only because the shell grammar has no token
 * that spans them — a block comment would be torn in half by this.
 */
function terminalLines(code, html) {
    const sources = code.split('\n');

    return html.split('\n').map((line, i) => {
        const text = (sources[i] ?? '').trim();
        const isCommand = text !== ''
            && !text.startsWith('#')
            && !/^[A-Z_][A-Z0-9_]*=/.test(text);

        return `<span class="ln${isCommand ? ' ln--cmd' : ''}">${line || ' '}</span>`;
    }).join('\n');
}
