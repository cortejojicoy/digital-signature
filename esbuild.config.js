import esbuild from 'esbuild';

const watch = process.argv.includes('--watch');

const options = {
    entryPoints: ['resources/js/index.js'],
    outfile: 'resources/dist/signature.js',
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

if (watch) {
    const ctx = await esbuild.context(options);
    await ctx.watch();
} else {
    await esbuild.build(options);
}
