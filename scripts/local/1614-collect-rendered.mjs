/**
 * Drives the #1614 functional-contrast sweep and writes the raw measurements.
 *
 * Local audit tooling, not platform code -- run with:
 *   node scripts/local/1614-collect-rendered.mjs > /tmp/1614-measurements.json
 *
 * Pipeline:
 *   1614-functional-contrast-fixture.php  builds the pages
 *   1614-collect-rendered.mjs (this)      sweeps global themes, evaluates
 *                                         1614-measure-rendered.js per page
 *   component-library-twig                turns the JSON into the pass/fail
 *     functional-element-contrast.mjs     table committed with the fix
 *
 * The split is deliberate and follows #1613: what the cascade RESOLVES to is a
 * Drupal-render question and lives here; what the numbers MEAN is palette math
 * and lives in the component library beside the existing contrast helpers, so
 * the WCAG formulas are not restated in two repos.
 *
 * Global theme is a sitewide setting rather than a URL parameter, so the sweep
 * has to write it, rebuild, and read it back off the rendered page -- step 3 of
 * the four warnings in the #1613 screenshots README. Two of the other three
 * apply here too: capture wide enough that nothing collapses to a stacked
 * layout, and cache-bust every URL or the browser serves the previous theme's
 * page while the database says otherwise.
 */

import { execFileSync } from "node:child_process";
import { createRequire } from "node:module";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, "..", "..");

// playwright is not a dependency of this repo; it ships inside the globally
// installed @playwright/cli that the playwright-cli skill drives. Resolving it
// from there rather than adding a dependency keeps this local-only script from
// touching the repo's package.json.
const require = createRequire(import.meta.url);
const playwrightRoot = execFileSync("npm", ["root", "-g"], {
  encoding: "utf8",
}).trim();
const { chromium } = require(join(
  playwrightRoot,
  "@playwright",
  "cli",
  "node_modules",
  "playwright"
));

/**
 * This checkout's own Lando host, derived rather than hardcoded.
 *
 * Several yalesites-project checkouts run side by side and they are NOT
 * interchangeable -- different Lando app names, different Pantheon sites,
 * different branches. A literal host here happens to work in the checkout it
 * was written in and silently sweeps the wrong site (or fails obscurely) in
 * the next one.
 */
const SITE = `https://${
  readFileSync(join(repoRoot, ".lando.local.yml"), "utf8").match(
    /^name:\s*(\S+)/m
  )[1]
}.lndo.site`;
const GLOBAL_THEMES = ["one", "two", "three", "four", "five", "six", "seven"];

/** The block types 1614-functional-contrast-fixture.php builds a page for. */
const COMPONENTS = ["accordion", "link_grid", "wrapped_text_callout"];

// The measure file is an IIFE *statement*; `page.evaluate` wants an
// *expression*, so the trailing semicolon has to go or the whole thing is a
// syntax error in the page.
const measureSource = readFileSync(
  join(here, "1614-measure-rendered.js"),
  "utf8"
).replace(/;\s*$/, "");

const lando = (args) =>
  execFileSync("lando", args, {
    cwd: repoRoot,
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
  });

/**
 * Resolve each fixture page to `/node/<nid>` by title.
 *
 * Not the path alias: Drupal strips underscores when generating one, so the
 * `link_grid` page is served at `.../linkgrid`, and hardcoding either spelling
 * makes the sweep depend on pathauto's pattern. The node ID is what the
 * fixture actually prints.
 */
const pagePaths = Object.fromEntries(
  COMPONENTS.map((component) => {
    const title = `1614 Functional contrast - ${component}`;
    const nid = lando([
      "drush",
      "sqlq",
      `SELECT nid FROM node_field_data WHERE title = '${title}' LIMIT 1`,
    ]).trim();

    if (!nid) {
      throw new Error(
        `No fixture node for ${component} -- run 1614-functional-contrast-fixture.php first`
      );
    }

    return [component, `/node/${nid}`];
  })
);

const setGlobalTheme = (theme) => {
  lando([
    "drush",
    "ev",
    `\\Drupal::service('ys_themes.theme_settings_manager')->setSetting('global_theme','${theme}');`,
  ]);
  lando(["drush", "cr"]);
};

/**
 * The global theme the site was on before this sweep started.
 *
 * Global theme is a sitewide setting, so a sweep MUTATES shared state. Left
 * unrestored it exits with the site on whichever theme it happened to finish
 * on -- and the throw paths below leave it mid-sweep -- which the next person
 * to open the site sees as the site having spontaneously changed colour.
 * Restored in the `finally`.
 */
const originalTheme = lando([
  "drush",
  "ev",
  "print \\Drupal::service('ys_themes.theme_settings_manager')->getSetting('global_theme');",
]).trim();

const browser = await chromium.launch();
// 1600px clears $break-2xl (1400px), so nothing under measurement collapses to
// a stacked layout with zero-width borders.
const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });

const rows = [];
const expectedElements = new Set();

try {
  for (const theme of GLOBAL_THEMES) {
    setGlobalTheme(theme);

    for (const [component, path] of Object.entries(pagePaths)) {
      await page.goto(
        `${SITE}${path}?cb=${Date.now()}${process.hrtime.bigint()}`,
        {
          // `networkidle` is too strict here: these fixture pages carry 36-42
          // sections, and the first load after a `drush cr` rebuilds enough of the
          // render cache that the network never goes quiet inside 30s. Waiting for
          // the markup under measurement is both faster and the thing that
          // actually has to be true.
          waitUntil: "domcontentloaded",
          timeout: 120000,
        }
      );
      await page.waitForSelector(".yds-layout[data-component-theme]", {
        timeout: 120000,
      });

      // Read the theme back off the DOM. Trusting the drush write alone is how a
      // sweep silently records seven copies of one theme.
      const rendered = await page.getAttribute(
        "[data-global-theme]",
        "data-global-theme"
      );
      if (rendered !== theme) {
        throw new Error(
          `Expected global theme ${theme} on ${path}, page rendered ${rendered}`
        );
      }

      // Accordion items collapse on load; their contents keep resolved styles
      // while collapsed, but expanding makes the same measurement also true of
      // what a visitor can actually see, and is what the screenshots capture.
      await page.evaluate(() => {
        document
          .querySelectorAll('[data-accordion-expanded="false"]')
          .forEach((item) =>
            item.setAttribute("data-accordion-expanded", "true")
          );
      });

      const { rows: measured, expected } = await page.evaluate(measureSource);
      rows.push(...measured.map((row) => ({ ...row, page: component })));
      expected.forEach((name) => expectedElements.add(name));

      process.stderr.write(
        `${theme} / ${component}: ${measured.length} rows\n`
      );
    }
  }

  /**
   * An element that never rendered is a hole in the audit, not a pass.
   *
   * The first run of this sweep reported zero failures for the wrapped callout
   * heading, the callout links and the link grid column heading -- because the
   * cloned source blocks carried none of them, so the selectors matched
   * nothing and the rows were simply absent. A table built from that data
   * would have claimed coverage it did not have.
   *
   * Two checks, because the obvious one is not enough:
   *
   * 1. Every element the measure script LOOKED FOR produced at least one row.
   * 2. For each element, the same set of (dial, section) cells rendered in
   *    EVERY global theme. Check 1 alone is satisfied by a single row anywhere
   *    in the sweep, so an element that rendered in one theme and vanished in
   *    the other six would pass it while 6/7 of its cells were missing.
   *
   * Deliberately not asserting a flat dials x sections x themes count: the
   * zero-width-border skip in the measure script legitimately drops
   * `item separator` for dialled accordions and `decorative left accent` for
   * the undialled one, so an absolute count would false-fail. Uniformity
   * across the theme axis is the invariant that actually holds.
   */
  const measured = new Set(
    rows.map((row) => `${row.component} | ${row.element}`)
  );
  const missing = [...expectedElements].filter((name) => !measured.has(name));

  if (missing.length) {
    throw new Error(
      `These elements never rendered, so they were not measured:\n  ${missing.join(
        "\n  "
      )}\n` +
        "Fix the fixture content (1614-functional-contrast-fixture.php) rather than " +
        "reporting them as passing."
    );
  }

  const uneven = [...measured].filter((name) => {
    const cellsPerTheme = GLOBAL_THEMES.map((theme) =>
      rows
        .filter(
          (row) =>
            `${row.component} | ${row.element}` === name &&
            row.globalTheme === theme
        )
        .map((row) => `${row.dial}/${row.sectionTheme}`)
        .sort()
        .join(",")
    );

    return new Set(cellsPerTheme).size !== 1;
  });

  if (uneven.length) {
    throw new Error(
      `These elements rendered in different cells depending on the global theme, ` +
        `so some cells are missing:\n  ${uneven.join("\n  ")}`
    );
  }

  process.stderr.write("\nElements measured:\n");
  for (const name of [...measured].sort()) {
    const count = rows.filter(
      (row) => `${row.component} | ${row.element}` === name
    ).length;
    process.stderr.write(`  ${String(count).padStart(4)}  ${name}\n`);
  }

  process.stdout.write(`${JSON.stringify(rows, null, 2)}\n`);
} finally {
  // Always, including on the throw paths above.
  setGlobalTheme(originalTheme);
  process.stderr.write(`\nrestored global theme: ${originalTheme}\n`);
}
