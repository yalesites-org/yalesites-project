/**
 * Captures the #1614 before/after evidence images.
 *
 * Local audit tooling, not platform code -- run with:
 *   node scripts/local/1614-capture.mjs <before|after> <output-dir>
 *
 * Element captures rather than full-page ones: the fixture pages hold 36-42
 * sections each, so a full-page image of one is unreadable at any size a ticket
 * comment will show. Each capture is the single section containing the failing
 * (dial x section theme) pairing, which is what a reviewer needs to compare.
 *
 * Only the cells the audit found FAILING are captured, in the two global themes
 * that bracket the range -- one (Old Blues, the darkest section-one background)
 * and four (Onha, where the slot-two/slot-five swap applies). The full numeric
 * evidence for all 4410 measurements is the generated table, not these images.
 *
 * The same four warnings from #1613's screenshots README apply; the two that
 * bite here are cache-busting every URL and reading the rendered global theme
 * back off the DOM.
 */

import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const [state, outputDir] = process.argv.slice(2);
if (!['before', 'after'].includes(state) || !outputDir) {
  throw new Error('Usage: node 1614-capture.mjs <before|after> <output-dir>');
}

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..');

const require = createRequire(import.meta.url);
const playwrightRoot = execFileSync('npm', ['root', '-g'], { encoding: 'utf8' }).trim();
const { chromium } = require(join(playwrightRoot, '@playwright', 'cli', 'node_modules', 'playwright'));

const SITE = 'https://yalesites-platform.lndo.site';

/**
 * The failing cells worth a picture.
 *
 * `dial` and `sectionTheme` identify the section on the fixture page; the
 * fixture labels each section `<type> / dial <d> / section <s>` and orders them
 * dial-major, so the index is deterministic.
 */
const CELLS = [
  { component: 'link_grid', dial: 'two', sectionTheme: 'one', note: 'link-grid-heading-dial-two-section-one' },
  { component: 'link_grid', dial: 'six', sectionTheme: 'three', note: 'link-grid-heading-dial-six-section-three' },
  { component: 'wrapped_text_callout', dial: 'one', sectionTheme: 'one', note: 'callout-heading-dial-one-section-one' },
  { component: 'wrapped_text_callout', dial: 'four', sectionTheme: 'four', note: 'callout-heading-dial-four-section-four' },
];

const GLOBAL_THEMES = { one: 'old-blues', four: 'onha' };

const lando = (args) =>
  execFileSync('lando', args, { cwd: repoRoot, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });

const nid = (component) =>
  lando([
    'drush',
    'sqlq',
    `SELECT nid FROM node_field_data WHERE title = '1614 Functional contrast - ${component}' LIMIT 1`,
  ]).trim();

mkdirSync(outputDir, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });

for (const [theme, label] of Object.entries(GLOBAL_THEMES)) {
  lando([
    'drush',
    'ev',
    `\\Drupal::service('ys_themes.theme_settings_manager')->setSetting('global_theme','${theme}');`,
  ]);
  lando(['drush', 'cr']);

  for (const cell of CELLS) {
    await page.goto(`${SITE}/node/${nid(cell.component)}?cb=${Date.now()}${process.hrtime.bigint()}`, {
      // `networkidle` is too strict here: these fixture pages carry 36-42
      // sections, and the first load after a `drush cr` rebuilds enough of the
      // render cache that the network never goes quiet inside 30s. Waiting for
      // the markup under measurement is both faster and the thing that
      // actually has to be true.
      waitUntil: 'domcontentloaded',
      timeout: 120000,
    });
    await page.waitForSelector('.yds-layout[data-component-theme]', { timeout: 120000 });

    const rendered = await page.getAttribute('[data-global-theme]', 'data-global-theme');
    if (rendered !== theme) {
      throw new Error(`Expected global theme ${theme}, page rendered ${rendered}`);
    }

    // Find the section holding this (dial x section theme) pairing by reading
    // the DOM rather than counting: the fixture's section order is an
    // implementation detail of the PHP loop, and a positional index would go
    // silently wrong the moment that loop changes.
    const handle = await page.evaluateHandle(
      ([root, dial, sectionTheme]) =>
        [...document.querySelectorAll(`.yds-layout[data-component-theme='${sectionTheme}']`)].find(
          (section) => section.querySelector(`${root}[data-component-theme='${dial}']`),
        ),
      [
        { link_grid: '.link-grid', wrapped_text_callout: '.wrapped-callout' }[cell.component],
        cell.dial,
        cell.sectionTheme,
      ],
    );

    const element = handle.asElement();
    if (!element) {
      throw new Error(`No section for ${cell.component} dial ${cell.dial} section ${cell.sectionTheme}`);
    }

    await element.scrollIntoViewIfNeeded();
    await element.screenshot({
      path: join(outputDir, `${state}-global-${theme}-${label}-${cell.note}.png`),
    });
    process.stderr.write(`${state} ${theme} ${cell.note}\n`);
  }
}

await browser.close();
