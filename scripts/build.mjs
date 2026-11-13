// Builds resources/dist/ (committed, so Composer users need no npm):
//   holler.js       the Alpine component, from resources/js/holler.js, minified
//   holler.css      the widget's stylesheet, from resources/css/holler.css, minified
//   holler-core.js  the @packstub/holler engine, its own minified build, copied as is
//
//   node scripts/build.mjs          from node_modules/@packstub/holler
//   node scripts/build.mjs --local  from the checkout beside this one (../holler-js, built first)
//   node scripts/build.mjs --check  fail when resources/dist is not what the sources build to
import { build } from 'esbuild'
import { execFileSync } from 'node:child_process'
import { readFile, writeFile } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const local = process.argv.includes('--local')
const check = process.argv.includes('--check')
const engine = local ? join(root, '../holler-js') : join(root, 'node_modules/@packstub/holler')

if (local) {
    execFileSync('npm', ['run', 'build'], { cwd: engine, stdio: 'inherit' })
}

const result = await build({
    absWorkingDir: root,
    entryPoints: ['resources/js/holler.js', 'resources/css/holler.css'],
    outdir: 'resources/dist',
    entryNames: '[name]',
    // Not bundled: the component imports the engine at run time, by a URL beside its own.
    bundle: false,
    format: 'esm',
    // ES2022 for the top-level await of that import.
    target: 'es2022',
    minify: true,
    legalComments: 'none',
    banner: { js: '/*! packstub/filament-holler | MIT */', css: '/*! packstub/filament-holler | MIT */' },
    write: false,
})

const files = [
    ...result.outputFiles.map((file) => ({ path: file.path, text: file.text })),
    { path: join(root, 'resources/dist/holler-core.js'), text: await readFile(join(engine, 'dist/index.js'), 'utf8') },
]

let stale = false

for (const file of files) {
    const name = file.path.slice(root.length + 1)

    if (check) {
        if ((await readFile(file.path, 'utf8').catch(() => null)) !== file.text) {
            console.error(`${name} is out of date: run npm run build:js`)
            stale = true
        }

        continue
    }

    await writeFile(file.path, file.text)
    console.log(`${name}  ${(Buffer.byteLength(file.text) / 1024).toFixed(1)} kB`)
}

process.exit(stale ? 1 : 0)
