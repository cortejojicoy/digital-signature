/**
 * The launcher's placement pass, exercised against a simulated DOM.
 *
 * The factory is extracted from the Blade view at run time rather than copied
 * here, so this cannot quietly drift from the code that actually ships.
 *
 * Run with: npm run test:js   (node only, no dependencies)
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const blade = fs.readFileSync(
    path.join(root, 'resources/views/filament/livewire/signature-launcher.blade.php'),
    'utf8',
)

const factorySource = blade.match(/<script>([\s\S]*?)<\/script>/)?.[1]

if (! factorySource) {
    console.error('Could not find the placement script in the launcher view.')
    process.exit(1)
}

const VW = 1200
const VH = 800
const FAB = 56
const BASE = 24 // 1.5rem at a 16px root

class El {
    constructor({ position = 'fixed', rect, pointerEvents = 'auto', visibility = 'visible', sel = [] }) {
        this.position = position
        this.rect = rect
        this.pointerEvents = pointerEvents
        this.visibility = visibility
        this.sel = sel
    }

    getBoundingClientRect() {
        return this.rect
    }

    matches(selector) {
        return this.sel.includes(selector)
    }

    contains() {
        return false
    }
}

const rect = (left, top, width, height) => ({
    left, top, width, height, right: left + width, bottom: top + height,
})

const overlaps = (a, b) =>
    a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top

function place({ others = [], config }) {
    let stack = 0

    const root = {
        style: {
            setProperty: (key, value) => {
                if (key === '--dsig-stack') stack = parseFloat(value)
            },
        },
        dataset: {},
        contains: () => false,
    }

    const box = () => {
        const top = config.position.startsWith('top')
            ? BASE + stack
            : VH - BASE - stack - FAB
        const left = config.position.endsWith('left')
            ? BASE
            : VW - BASE - FAB

        return rect(left, top, FAB, FAB)
    }

    global.window = { innerHeight: VH, innerWidth: VW }
    global.getComputedStyle = (el) => el
    global.document = {
        body: {},
        documentElement: {},
        elementsFromPoint: (x, y) => others.filter((el) => {
            const r = el.rect

            return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom
                && el.pointerEvents !== 'none'
        }),
        querySelectorAll: (selector) => others.filter((el) => el.sel.includes(selector)),
    }

    // eslint-disable-next-line no-eval
    eval(factorySource)

    const component = window.dsigLauncher(config)

    Object.assign(component, { $el: root, $refs: { fab: { getBoundingClientRect: box } }, open: false })
    component.place()

    return { stack, box: box() }
}

const base = { enabled: true, position: 'bottom-right', gap: 12, avoid: [], ignore: [] }

let failures = 0

function check(name, passed, detail = '') {
    if (! passed) failures++

    console.log(`${passed ? '  ✓ ' : '  ✗ '}${name}${detail}`)
}

console.log('\nlauncher placement\n')

// An empty corner is left alone: placement must not move a button nobody is
// competing with.
check(
    'leaves the button at its configured offset when the corner is free',
    place({ config: base }).stack === 0,
)

// The case this exists for: the host app already has a floating button there.
{
    const hostFab = new El({ rect: rect(1116, 700, 60, 60) })
    const result = place({ others: [hostFab], config: base })

    check(
        'stacks clear of the host app\'s own floating button',
        result.stack > 0 && ! overlaps(result.box, hostFab.rect),
        ` (moved ${result.stack}px)`,
    )
}

// Chat bubble above a cookie button — both have to be cleared, in one pass each.
{
    const cookie = new El({ rect: rect(1116, 720, 60, 60) })
    const chat = new El({ rect: rect(1116, 640, 60, 60) })
    const result = place({ others: [cookie, chat], config: base })

    check(
        'clears two stacked widgets',
        ! overlaps(result.box, cookie.rect) && ! overlaps(result.box, chat.rect),
        ` (moved ${result.stack}px)`,
    )
}

// A full-height sidebar is layout, not an obstacle. Stacking above one would
// push the button off the top of the screen.
check(
    'ignores full-height layout such as a sidebar',
    place({
        others: [new El({ rect: rect(0, 0, 280, 800) })],
        config: { ...base, position: 'bottom-left' },
    }).stack === 0,
)

// A topbar is the opposite case: wide, short, and must be cleared.
{
    const result = place({
        others: [new El({ rect: rect(0, 0, 1200, 64) })],
        config: { ...base, position: 'top-right' },
    })

    check('clears a topbar when placed in a top corner', result.box.top >= 64, ` (top ${result.box.top}px)`)
}

// Toast rails paint over the corner but don't take clicks.
check(
    'ignores pointer-events: none decoration',
    place({
        others: [new El({ rect: rect(900, 600, 300, 200), pointerEvents: 'none' })],
        config: base,
    }).stack === 0,
)

check(
    'ignores ordinary, non-fixed page content',
    place({
        others: [new El({ position: 'static', rect: rect(1000, 600, 200, 200) })],
        config: base,
    }).stack === 0,
)

check(
    'honours the ignore list',
    place({
        others: [new El({ rect: rect(1116, 700, 60, 60), sel: ['.toast-rail'] })],
        config: { ...base, ignore: ['.toast-rail'] },
    }).stack === 0,
)

// Widgets the point test cannot see (iframes, pointer-events: none) can be
// named in config instead.
check(
    'honours the avoid list for widgets the point test cannot see',
    place({
        others: [new El({ rect: rect(1116, 700, 60, 60), pointerEvents: 'none', sel: ['#intercom'] })],
        config: { ...base, avoid: ['#intercom'] },
    }).stack > 0,
)

// A corner crowded past all reason: give up rather than drift into the middle
// of the page, which would be worse than the overlap.
{
    const wall = Array.from({ length: 12 }, (_, i) => new El({ rect: rect(1116, 740 - (i * 60), 60, 60) }))
    const result = place({ others: wall, config: base })

    check(
        'caps the stack instead of drifting up the page',
        result.stack <= VH * 0.6,
        ` (capped at ${result.stack}px)`,
    )
}

console.log(`\n${failures === 0 ? 'All placement checks passed.' : `${failures} placement check(s) failed.`}\n`)

process.exit(failures === 0 ? 0 : 1)
