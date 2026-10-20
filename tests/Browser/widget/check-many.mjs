/*
 * Three widgets on one page, and dark mode.
 *
 * The first part is a regression guard for a real bug: settings used to live
 * in the script file's closure, so the second script tag on a page found the
 * custom element already defined and quietly became a copy of the first. A
 * venue putting sunbeds on one page and dinner on another was fine; putting
 * both on the same page was not.
 *
 * The second part guards another: the stylesheet selects on the theme
 * attribute, so an element that never received one loses the dark mode that
 * follows the visitor's device.
 */
import { chromium } from 'playwright';

const launch = process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {};

const browser = await chromium.launch(launch);
const problems = [];

/* --- Three widgets, three sets of settings ------------------------------- */

const page = await browser.newPage();

page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));

await page.goto('http://127.0.0.1:8799/two.html', { waitUntil: 'networkidle' });
await page.waitForTimeout(400);

const widgets = page.locator('tablereserved-search');
const count = await widgets.count();

const many = { count, labels: [], accents: [], pinnedTypes: [], layouts: [] };

for (let i = 0; i < count; i++) {
    const w = widgets.nth(i);
    const hasButton = (await w.locator('button').count()) > 0;

    many.labels.push(
        ((await (hasButton ? w.locator('button') : w.locator('a.cta')).textContent()) ?? '').trim()
    );
    many.accents.push(await w.evaluate((n) => n.style.getPropertyValue('--tr-accent')));
    many.layouts.push(await w.getAttribute('layout'));
}

many.pinnedTypes = await widgets.evaluateAll((ns) => ns.map((n) => n.getAttribute('booking-type')));
many.buttonHref = await page.locator('#c tablereserved-search a.cta').getAttribute('href');

await page.close();

/* --- Dark mode ----------------------------------------------------------- */

const themes = {};

for (const scheme of ['light', 'dark']) {
    const themed = await browser.newPage({ colorScheme: scheme });

    await themed.goto('http://127.0.0.1:8799/two.html', { waitUntil: 'networkidle' });
    await themed.waitForTimeout(300);

    const w = themed.locator('tablereserved-search').first();

    themes[scheme] = {
        theme: await w.getAttribute('theme'),
        background: await w.locator('form').evaluate((f) => getComputedStyle(f).backgroundColor),
    };

    await themed.close();
}

// An explicit choice must beat the device.
const override = await browser.newPage({ colorScheme: 'dark' });

await override.goto('http://127.0.0.1:8799/', { waitUntil: 'networkidle' });
await override.waitForTimeout(300);

themes.explicitLight = {
    theme: await override.locator('tablereserved-search').getAttribute('theme'),
    background: await override.locator('tablereserved-search form')
        .evaluate((f) => getComputedStyle(f).backgroundColor),
};

await override.close();

/* --- A venue that has not renewed --------------------------------------- */

const lapsed = await browser.newPage();

lapsed.on('pageerror', (e) => problems.push('pageerror: ' + e.message));

await lapsed.goto('http://127.0.0.1:8799/closed.html', { waitUntil: 'networkidle' });
await lapsed.waitForTimeout(400);

const closed = {
    notice: ((await lapsed.locator('tablereserved-search .note').textContent()) ?? '').trim(),
    stillHasAForm: (await lapsed.locator('tablereserved-search form').count()) > 0,
};

await lapsed.close();

console.log(JSON.stringify({ many, themes, closed, problems }, null, 2));

const expected = [
    [many.count === 3, 'not all three widgets rendered'],
    [new Set(many.labels).size === 3, 'two widgets share a label — settings are leaking between them'],
    [many.pinnedTypes[0] === 'sunbeds' && many.pinnedTypes[1] === 'tables',
        'the pinned booking types did not stay separate'],
    [many.accents[0] !== many.accents[1], 'both widgets took the same accent'],
    [many.layouts[1] === 'vertical', 'the vertical layout was not applied'],
    [/people=6/.test(many.buttonHref ?? ''), 'the button-only widget lost its party size'],
    [themes.light.background === 'rgb(255, 255, 255)', 'the light background is wrong'],
    [themes.dark.background === 'rgb(28, 27, 25)', 'dark mode did not follow the device'],
    [themes.explicitLight.background === 'rgb(255, 255, 255)',
        'an explicit light theme lost to a dark device'],
    [closed.notice === 'Online reservations are temporarily unavailable.',
        'a venue that has not renewed did not show the sentence'],
    [closed.stillHasAForm === false,
        'a venue that has not renewed still shows a form that cannot work'],
    [problems.length === 0, 'the page reported errors'],
];

const failures = expected.filter(([ok]) => !ok).map(([, why]) => why);

await browser.close();

if (failures.length) {
    console.error('\nFAILED:\n  ' + failures.join('\n  '));
    process.exit(1);
}

console.log('\nAll checks passed.');
