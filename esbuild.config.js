import esbuild from 'esbuild';
import { copyFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const watch = process.argv.includes('--watch');
const require = createRequire(import.meta.url);

const shared = {
    bundle: true,
    minify: !watch,
    sourcemap: watch ? 'inline' : false,
    format: 'iife',
    platform: 'browser',
    target: ['es2020'],
    loader: { '.js': 'jsx', '.jsx': 'jsx' },
    jsx: 'automatic',
    define: { 'process.env.NODE_ENV': watch ? '"development"' : '"production"' },
    logLevel: 'info',
};

const builds = [
    {
        ...shared,
        entryPoints: ['resources/js/index.js'],
        outfile: 'resources/dist/digital-signature.js',
    },
    {
        // Separate bundle, loaded on demand by resources/js/lazy/pdfViewer.js.
        // pdf.js dwarfs everything else in this package and the launcher is on
        // every panel page, so it must not ride along in the main bundle.
        ...shared,
        entryPoints: ['resources/js/pdf-viewer-entry.js'],
        outfile: 'resources/dist/digital-signature-pdf-viewer.js',
    },
];

/**
 * The pdf.js worker ships as an ES module and pdf.js always instantiates it
 * with `{ type: 'module' }`, so it is copied verbatim rather than bundled to
 * an IIFE. The `.js` extension is deliberate: `.mjs` is not in every web
 * server's mime table, and a worker served as the wrong content type is
 * rejected outright.
 *
 * Copying it at all — instead of pointing workerSrc at a CDN, which is the
 * usual pdf.js recipe — is what lets the viewer work inside a firewalled
 * admin panel.
 */
function copyWorker() {
    copyFileSync(
        require.resolve('pdfjs-dist/legacy/build/pdf.worker.min.mjs'),
        'resources/dist/digital-signature-pdf.worker.js',
    );
}

if (watch) {
    copyWorker();
    for (const options of builds) {
        const ctx = await esbuild.context(options);
        await ctx.watch();
    }
} else {
    for (const options of builds) {
        await esbuild.build(options);
    }
    copyWorker();
}
