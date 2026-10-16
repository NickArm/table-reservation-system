/*
 * One widget, on a page with deliberately hostile CSS.
 *
 * The host page does `all: unset` on form controls and forces
 * `box-sizing: content-box !important` — the kind of thing a real WordPress
 * theme does. If the Shadow DOM is doing its job, none of it reaches us.
 */
import { chromium } from 'playwright';

// Playwright's own download by default. The env var is for a container that
// already has a browser somewhere else.
const launch = process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {};

const browser = await chromium.launch(launch);
const page = await browser.newPage();

const problems = [];

page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
page.on('console', (m) => {
    if (m.type() === 'error') {
        problems.push('console: ' + m.text());
    }
});

await page.goto('http://127.0.0.1:8799/', { waitUntil: 'networkidle' });

const widget = page.locator('tablereserved-search');

await widget.waitFor({ timeout: 5000 });

const report = {
    shadowRoot: await widget.evaluate((n) => !!n.shadowRoot),
    origin: await widget.getAttribute('origin'),
    people: await page.locator('tablereserved-search #tr-people').inputValue(),
    label: (await page.locator('tablereserved-search button').textContent())?.trim(),
    times: await page.locator('tablereserved-search #tr-time option').allTextContents(),
    typeSelectorShown: await page.locator('tablereserved-search [data-type]').isVisible(),
    types: await page.locator('tablereserved-search #tr-type option').allTextContents(),

    // The two that prove the isolation: our colour and our type size, not the
    // host page's red 40px.
    buttonColour: await page.locator('tablereserved-search button')
        .evaluate((b) => getComputedStyle(b).backgroundColor),
    buttonFontSize: await page.locator('tablereserved-search button')
        .evaluate((b) => getComputedStyle(b).fontSize),
};

await page.locator('tablereserved-search button').click();
await page.waitForURL(/\/reservation\//, { timeout: 5000 });

report.landedOn = page.url();
report.problems = problems;

console.log(JSON.stringify(report, null, 2));

const expected = [
    [report.shadowRoot === true, 'no shadow root'],
    [report.buttonColour === 'rgb(201, 162, 39)', 'the accent did not reach the button'],
    [report.buttonFontSize === '15px', "the host page's type size leaked in"],
    [report.people === '4', 'data-people was ignored'],
    [report.label === 'Find a sunbed', 'data-label was ignored'],
    [report.times.length === 2, 'times were not loaded'],
    [report.typeSelectorShown === true, 'the booking type selector is missing'],
    [/channel=widget/.test(report.landedOn), 'attribution is missing from the booking URL'],
    [/people=4/.test(report.landedOn), 'the party size did not travel'],
    [problems.length === 0, 'the page reported errors'],
];

const failures = expected.filter(([ok]) => !ok).map(([, why]) => why);

await browser.close();

if (failures.length) {
    console.error('\nFAILED:\n  ' + failures.join('\n  '));
    process.exit(1);
}

console.log('\nAll checks passed.');
