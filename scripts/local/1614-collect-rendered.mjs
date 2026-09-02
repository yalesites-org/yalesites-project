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
 *
 * LINK INTERACTION STATES. #1614 AC #1 names links resting, `:hover` and
 * `:focus-visible`, but the first pass of this sweep measured only the resting
 * colour, so a hover pairing below 4.5:1 could not appear in the table at all
 * (component-library-twig#714). A page cannot ask for a pseudo-class --
 * `getComputedStyle` has no `:hover` form -- so the state is forced through
 * CDP's `CSS.forcePseudoState` and the page is then measured normally. That
 * reads the real resolved colour out of the full cascade, which is the whole
 * reason this audit renders rather than computing from tokens.
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

/**
 * One measuring pass per interaction state, in this order.
 *
 * `resting` forces nothing, so it also leaves the page in a clean state for
 * whichever pass runs next. Kept in step with `LINK_STATES` in
 * 1614-measure-rendered.js, which decides which ELEMENTS each state applies
 * to; this list decides which passes are RUN.
 */
const STATES = ["resting", "hover", "focus-visible"];

/**
 * The nodes whose pseudo-state is forced.
 *
 * Every link inside the three blocks under audit, rather than the fine-grained
 * per-element selectors -- those live in 1614-measure-rendered.js and
 * restating them here is how the two drift apart. Only links declare a state
 * beyond `resting`, so this is exactly the set that needs forcing; the measure
 * script then picks which of them belongs in the pass being run.
 */
const LINK_SELECTOR = ".accordion a, .link-grid a, .wrapped-callout a";

// The measure file is a function *expression* terminated by a semicolon;
// `page.evaluate` wants an expression, so the trailing semicolon has to go or
// the whole thing is a syntax error in the page. It takes the state to measure,
// so it is wrapped in a call rather than evaluated bare -- explicitly, rather
// than relying on `page.evaluate`'s own string-as-function handling, so what
// runs in the page is visible here.
const measureSource = readFileSync(
  join(here, "1614-measure-rendered.js"),
  "utf8"
).replace(/;\s*$/, "");

const measureCall = (state) => `(${measureSource})(${JSON.stringify(state)})`;

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

/**
 * A theme name safe to interpolate into a `drush ev` PHP string.
 *
 * `originalTheme` below is read out of the DATABASE, not off a hardcoded list,
 * and it goes straight back into a single-quoted PHP string literal that
 * `drush ev` evaluates. A `'` in the stored value would close that literal and
 * the rest would run as PHP inside the appserver.
 *
 * Not reachable today -- `ThemesSettingsForm` renders the setting as `radios`
 * with `#options`, so core rejects an off-list value, and every other writer
 * (`drush cset`, config import, direct SQL) already has more privilege than the
 * eval would grant. Guarded anyway: `ThemeSettingsManager::setSetting()` does no
 * validation of its own, so this is the only place the shape is checked, and
 * "unvalidated DB value reaches an eval" is not a pattern worth leaving for the
 * next script to copy.
 */
const themeName = (value) => {
  if (!/^[a-z0-9_-]+$/.test(value)) {
    throw new Error(
      `Refusing to interpolate global theme ${JSON.stringify(value)} into a ` +
        "drush ev PHP string: expected a machine name matching /^[a-z0-9_-]+$/"
    );
  }

  return value;
};

const setGlobalTheme = (theme) => {
  lando([
    "drush",
    "ev",
    `\\Drupal::service('ys_themes.theme_settings_manager')->setSetting('global_theme','${themeName(
      theme
    )}');`,
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
const originalTheme = themeName(
  lando([
    "drush",
    "ev",
    "print \\Drupal::service('ys_themes.theme_settings_manager')->getSetting('global_theme');",
  ]).trim()
);

const browser = await chromium.launch();
// 1600px clears $break-2xl (1400px), so nothing under measurement collapses to
// a stacked layout with zero-width borders.
const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });

/**
 * Measure SETTLED colors, not colors mid-animation.
 *
 * `00-tokens/effects/_effects.scss` transitions `color` on every link, so
 * reading `getComputedStyle` straight after forcing `:hover` returns an
 * interpolated value part-way between the resting and the hover color. That is
 * not a subtle inaccuracy: the first run of this sweep reported the link grid's
 * hover as PASSING on section six for dial six (rgb(33,34,34), barely off
 * resting) and FAILING for dial one (rgb(39,107,190), fully arrived) -- the
 * same CSS, two verdicts, decided by where in a 0.15s ease-in-out each read
 * happened to land.
 *
 * Emulating `prefers-reduced-motion: reduce` is the library's OWN escape hatch:
 * the transition is declared inside
 * `@media (prefers-reduced-motion: no-preference)`, so under this setting it is
 * never emitted and the color arrives instantly. Nothing foreign is injected
 * into the page, and the target colors -- the ones a visitor actually reads --
 * are identical either way.
 */
await page.emulateMedia({ reducedMotion: "reduce" });

/**
 * Chrome DevTools Protocol session, for `CSS.forcePseudoState`.
 *
 * The only way to make `:hover` and `:focus-visible` OBSERVABLE to
 * `getComputedStyle`. `page.hover()` would move a real pointer, which can only
 * be over one of the ~120 links on a page at a time and would turn each sweep
 * into thousands of round trips; reading the rules out of `document.styleSheets`
 * instead would re-implement the cascade, which is the one thing this audit
 * exists to avoid re-implementing.
 */
const cdp = await page.context().newCDPSession(page);
await cdp.send("DOM.enable");
await cdp.send("CSS.enable");

/**
 * Force (or clear) a pseudo-class on every link on the current page.
 *
 * `resting` clears, so the passes cannot leak into one another.
 */
const forceLinkState = async (nodeIds, state) => {
  const forcedPseudoClasses = state === "resting" ? [] : [state];

  for (const nodeId of nodeIds) {
    await cdp.send("CSS.forcePseudoState", { nodeId, forcedPseudoClasses });
  }
};

const rows = [];
const expectedElements = new Set();

try {
  for (const theme of GLOBAL_THEMES) {
    setGlobalTheme(theme);

    for (const [component, path] of Object.entries(pagePaths)) {
      await page.goto(
        `${SITE}${path}?cb=${Date.now()}${process.hrtime.bigint()}`,
        {
          // `networkidle` is too strict here: these fixture pages carry 42-49
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

      // Confirm the animation suppression is actually in effect. If it is not,
      // every forced-state reading is a race against a 0.15s colour transition
      // and the hover verdicts are decided by timing -- which is exactly the
      // failure this sweep hit once already, and it looks like real data.
      const motionReduced = await page.evaluate(
        () => window.matchMedia("(prefers-reduced-motion: reduce)").matches
      );
      if (!motionReduced) {
        throw new Error(
          "prefers-reduced-motion is not being emulated, so link colours are " +
            "transitioning while they are measured"
        );
      }

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

      // Node ids are per-document, so they are resolved after each
      // navigation. `DOM.getDocument` is what populates the protocol's node
      // map in the first place -- `DOM.querySelectorAll` returns nothing
      // without it.
      const { root } = await cdp.send("DOM.getDocument", { depth: -1 });
      const { nodeIds } = await cdp.send("DOM.querySelectorAll", {
        nodeId: root.nodeId,
        selector: LINK_SELECTOR,
      });

      // No links to force means the hover and focus passes would measure the
      // resting page and report it as a hover result -- a silent hole that
      // looks like coverage. Every one of these three blocks has links in the
      // fixture, so zero is a broken fixture or a broken selector.
      if (!nodeIds.length) {
        throw new Error(
          `No links matched ${LINK_SELECTOR} on ${path} (global theme ` +
            `${theme}), so the :hover and :focus-visible passes would silently ` +
            "re-measure the resting page"
        );
      }

      let pageRows = 0;

      for (const state of STATES) {
        await forceLinkState(nodeIds, state);

        const {
          rows: measured,
          expected,
          states: linkStates,
        } = await page.evaluate(measureCall(state));

        // The measure script owns which states an ELEMENT declares; this file
        // owns which passes are RUN. Neither can see the other, so the two
        // lists are compared rather than trusted to have been kept in step.
        const unrun = linkStates.filter((name) => !STATES.includes(name));
        if (unrun.length) {
          throw new Error(
            `1614-measure-rendered.js declares state(s) ${unrun.join(", ")} ` +
              "that this sweep never runs, so they would be absent from the " +
              "audit with no rows and no expectation to notice it"
          );
        }

        rows.push(...measured.map((row) => ({ ...row, page: component })));
        expected.forEach((name) => expectedElements.add(name));
        pageRows += measured.length;
      }

      process.stderr.write(`${theme} / ${component}: ${pageRows} rows\n`);
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
  const cellName = (row) => `${row.component} | ${row.element} | ${row.state}`;

  const measured = new Set(rows.map(cellName));
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
        .filter((row) => cellName(row) === name && row.globalTheme === theme)
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

  /**
   * Did forcing the pseudo-state actually DO anything?
   *
   * The failure mode this guards is the quiet one: if `CSS.forcePseudoState`
   * silently stopped applying -- a protocol rename, a session that never
   * attached, a selector that stopped matching -- every forced cell would come
   * back holding its resting colour and the table would report the interaction
   * states as passing. Nothing else in this script would notice, because the
   * rows are all present and all well-formed.
   *
   * BE PRECISE ABOUT WHAT THIS DOES AND DOES NOT CATCH. It is a sweep-wide
   * check: it fires only when NO forced state moved ANYWHERE. Forcing that
   * broke part-way through, or for one component, or for some palettes, would
   * get past it.
   *
   * A per-(global theme, page) assertion would be stronger and was tried, but
   * it FALSE-FAILS on correct data, so it would have to be suppressed to be
   * kept -- which is worse than not having it. Measured, post-fix: `:hover`
   * moves in 12 of the 21 (global theme x page) groups and in none of the
   * groups for global themes one, five and six, because in those palettes the
   * unthemed section's link colour already equals its hover colour. The two
   * facts that make a per-group rule impossible here:
   *
   *  - `:focus-visible` never changes a link's colour at all. The link atom's
   *    `&:focus-visible` only removes the sliding underline
   *    (`01-atoms/controls/text-link/_yds-text-link.scss`), so 0 of its 1225
   *    cells differ from resting. That is why its contrast verdicts match
   *    resting throughout the table, and it is measured rather than assumed.
   *  - On a THEMED section, `:hover` now resolves to the same section
   *    foreground as resting by design (the #1614 hover fix), so almost all
   *    remaining movement is in the unthemed section -- and only in the
   *    palettes where those two colours differ.
   *
   * `:hover` moving somewhere is what keeps this honest; it is the weakest
   * claim that cannot hold when forcing is broken outright.
   */
  const cellKey = (row) =>
    `${row.page}|${row.globalTheme}|${row.sectionTheme}|${row.component}` +
    `|${row.dial}|${row.element}`;

  const restingByCell = new Map(
    rows
      .filter((row) => row.state === "resting")
      .map((row) => [cellKey(row), row.value])
  );

  const forced = rows.filter((row) => row.state !== "resting");

  /**
   * A forced row with no resting row to compare against.
   *
   * Its own error rather than folded into the movement count. `get()` on an
   * absent key returns `undefined`, and `undefined !== "<colour>"` is true, so
   * a missing baseline would otherwise be counted as MOVEMENT -- inflating the
   * single number that proves the forcing worked, using exactly the coverage
   * gap the rest of this block exists to detect.
   */
  const unpaired = forced.filter((row) => !restingByCell.has(cellKey(row)));

  if (unpaired.length) {
    throw new Error(
      `${unpaired.length} forced-state measurement(s) have no resting ` +
        "measurement to compare against, so their cells were not measured in " +
        `every state. First: ${cellKey(unpaired[0])} | ${unpaired[0].state}`
    );
  }

  const movedPerState = STATES.filter((state) => state !== "resting").map(
    (state) => [
      state,
      forced.filter(
        (row) =>
          row.state === state && restingByCell.get(cellKey(row)) !== row.value
      ).length,
    ]
  );

  if (movedPerState.every(([, moved]) => moved === 0)) {
    throw new Error(
      "No forced-state measurement anywhere in the sweep differs from its " +
        "resting one, so CSS.forcePseudoState is not taking effect and the " +
        "interaction-state cells would be resting colours wearing a different " +
        "label"
    );
  }

  process.stderr.write("\nCells whose colour moved from resting:\n");
  for (const [state, moved] of movedPerState) {
    process.stderr.write(`  ${String(moved).padStart(4)}  :${state}\n`);
  }

  process.stderr.write("\nElements measured:\n");
  for (const name of [...measured].sort()) {
    const count = rows.filter((row) => cellName(row) === name).length;
    process.stderr.write(`  ${String(count).padStart(4)}  ${name}\n`);
  }

  process.stdout.write(`${JSON.stringify(rows, null, 2)}\n`);
} finally {
  // Both of these run, whichever of them throws.
  //
  // Order: the theme restore goes first, because a throw out of `close()` would
  // otherwise leave the site on whichever theme the sweep finished on -- the one
  // thing the restore exists to prevent. But it is wrapped in its own `try`, so
  // a throw out of the restore cannot skip `close()`: without the close the
  // process never exits (the browser's own handles keep the event loop alive),
  // which is exactly the hang this close was added to fix. Skipping it inside
  // the handler for a failed restore would reintroduce it on the error path
  // only, where it is hardest to notice.
  try {
    setGlobalTheme(originalTheme);
    process.stderr.write(`\nrestored global theme: ${originalTheme}\n`);
  } finally {
    await browser.close();
  }
}
