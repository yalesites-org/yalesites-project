/**
 * Captures the #1614 before/after evidence images.
 *
 * Local audit tooling, not platform code -- run with:
 *   node scripts/local/1614-capture.mjs <before|after> <output-dir>
 *
 * Element captures rather than full-page ones: the fixture pages hold 42-49
 * sections each, so a full-page image of one is unreadable at any size a ticket
 * comment will show. Each capture is the single section containing the failing
 * (dial x section theme) pairing, which is what a reviewer needs to compare.
 *
 * Only the cells the audit found FAILING are captured, in the two global themes
 * that bracket the range -- one (Old Blues, the darkest section-one background)
 * and four (Onha, where the slot-two/slot-five swap applies). The full numeric
 * evidence for every measurement is the generated table, not these images.
 *
 * A cell may name a `pseudo` state. Some of #1614's failures are only reachable
 * on `:hover`, so the state is forced through CDP (`CSS.forcePseudoState`) --
 * the same mechanism 1614-collect-rendered.mjs measures with -- rather than
 * moved to with a real pointer, which can only be over one link at a time.
 * `prefers-reduced-motion: reduce` is emulated for those captures because the
 * link atom transitions `color` over 0.15s, so an unsuppressed screenshot
 * catches a colour part-way to its target.
 *
 * The same four warnings from #1613's screenshots README apply; the two that
 * bite here are cache-busting every URL and reading the rendered global theme
 * back off the DOM.
 */

import { execFileSync } from "node:child_process";
import { createRequire } from "node:module";
import { mkdirSync, readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const [state, outputDir] = process.argv.slice(2);
if (!["before", "after"].includes(state) || !outputDir) {
  throw new Error("Usage: node 1614-capture.mjs <before|after> <output-dir>");
}

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, "..", "..");

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

/**
 * The failing cells worth a picture.
 *
 * `dial` and `sectionTheme` identify the section on the fixture page; the
 * fixture labels each section `<type> / dial <d> / section <s>` and orders them
 * dial-major, so the index is deterministic.
 */
const CELLS = [
  {
    component: "link_grid",
    dial: "two",
    sectionTheme: "one",
    note: "link-grid-heading-dial-two-section-one",
  },
  {
    component: "link_grid",
    dial: "six",
    sectionTheme: "three",
    note: "link-grid-heading-dial-six-section-three",
  },
  {
    component: "wrapped_text_callout",
    dial: "one",
    sectionTheme: "one",
    note: "callout-heading-dial-one-section-one",
  },
  {
    component: "wrapped_text_callout",
    dial: "four",
    sectionTheme: "four",
    note: "callout-heading-dial-four-section-four",
  },
  // The UNTHEMED section, added for #714. Two separate defects show in this one
  // image: the block heading is forced `--color-basic-white` by the link grid's
  // light-on-dark dial carve-out even though a link grid paints no background,
  // so it is white on the white page (1.00:1); and the links carry a descender
  // halo in the dial's colour rather than the page's.
  {
    component: "link_grid",
    dial: "one",
    sectionTheme: "default",
    note: "link-grid-dial-one-section-default",
  },
  // Hover-only failures, invisible to the first audit pass because it measured
  // the resting colour alone.
  {
    component: "link_grid",
    dial: "one",
    sectionTheme: "six",
    pseudo: "hover",
    note: "link-grid-link-hover-dial-one-section-six",
  },
  {
    component: "wrapped_text_callout",
    dial: "one",
    sectionTheme: "one",
    pseudo: "hover",
    note: "callout-link-hover-dial-one-section-one",
  },
];

const GLOBAL_THEMES = { one: "old-blues", four: "onha" };

/** Block-root selector per fixture page, shared by the lookup and the forcing. */
const COMPONENT_ROOTS = {
  link_grid: ".link-grid",
  wrapped_text_callout: ".wrapped-callout",
};

const lando = (args) =>
  execFileSync("lando", args, {
    cwd: repoRoot,
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
  });

const nid = (component) => {
  const found = lando([
    "drush",
    "sqlq",
    `SELECT nid FROM node_field_data WHERE title = '1614 Functional contrast - ${component}' LIMIT 1`,
  ]).trim();

  // Without this the empty result becomes `${SITE}/node/?cb=...`, which loads a
  // 404 and then fails much later as an opaque selector timeout. Same guard the
  // collector already has, for the same reason.
  if (!found) {
    throw new Error(
      `No fixture node for ${component} -- run ` +
        "1614-functional-contrast-fixture.php first"
    );
  }

  return found;
};

mkdirSync(outputDir, { recursive: true });

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
 * The theme the site was on before this run.
 *
 * Global theme is a sitewide setting, so capturing MUTATES shared state. Left
 * unrestored this exits with the site on whichever theme it finished on, which
 * the next person to open the site sees as the site having spontaneously
 * changed colour. Same discipline as 1614-collect-rendered.mjs; restored in the
 * `finally` below.
 */
const originalTheme = themeName(
  lando([
    "drush",
    "ev",
    "print \\Drupal::service('ys_themes.theme_settings_manager')->getSetting('global_theme');",
  ]).trim()
);

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });

// The link atom transitions `color` over 0.15s inside
// `@media (prefers-reduced-motion: no-preference)`, so a screenshot taken right
// after forcing `:hover` catches the colour mid-interpolation. Emulating the
// reduced-motion preference is the library's own switch for that transition, so
// the captured colour is the settled one.
await page.emulateMedia({ reducedMotion: "reduce" });

const cdp = await page.context().newCDPSession(page);
await cdp.send("DOM.enable");
await cdp.send("CSS.enable");

try {
  for (const [theme, label] of Object.entries(GLOBAL_THEMES)) {
    setGlobalTheme(theme);

    for (const cell of CELLS) {
      await page.goto(
        `${SITE}/node/${nid(
          cell.component
        )}?cb=${Date.now()}${process.hrtime.bigint()}`,
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

      const rendered = await page.getAttribute(
        "[data-global-theme]",
        "data-global-theme"
      );
      if (rendered !== theme) {
        throw new Error(
          `Expected global theme ${theme}, page rendered ${rendered}`
        );
      }

      // Find the section holding this (dial x section theme) pairing by reading
      // the DOM rather than counting: the fixture's section order is an
      // implementation detail of the PHP loop, and a positional index would go
      // silently wrong the moment that loop changes.
      const handle = await page.evaluateHandle(
        ([root, dial, sectionTheme]) =>
          [
            ...document.querySelectorAll(
              `.yds-layout[data-component-theme='${sectionTheme}']`
            ),
          ].find((section) =>
            section.querySelector(`${root}[data-component-theme='${dial}']`)
          ),
        [COMPONENT_ROOTS[cell.component], cell.dial, cell.sectionTheme]
      );

      const element = handle.asElement();
      if (!element) {
        throw new Error(
          `No section for ${cell.component} dial ${cell.dial} section ${cell.sectionTheme}`
        );
      }

      await element.scrollIntoViewIfNeeded();

      // Forced on the links inside THIS section only, so the image shows the
      // hovered state of the cell under discussion and nothing else on the page
      // is disturbed. The section is addressed with the same (section theme x
      // dial) pairing the handle above was found by, expressed as one selector
      // with `:has()` -- `DOM.querySelectorAll` takes a selector, not a handle,
      // and translating a Playwright ElementHandle into a protocol node id needs
      // its private object id.
      const forced = cell.pseudo
        ? (
            await cdp.send("DOM.querySelectorAll", {
              nodeId: (
                await cdp.send("DOM.getDocument", { depth: -1 })
              ).root.nodeId,
              selector:
                `.yds-layout[data-component-theme='${cell.sectionTheme}']` +
                `:has(${COMPONENT_ROOTS[cell.component]}` +
                `[data-component-theme='${cell.dial}']) a`,
            })
          ).nodeIds
        : [];

      if (cell.pseudo && !forced.length) {
        throw new Error(
          `No links to force :${cell.pseudo} on for ${cell.component} dial ` +
            `${cell.dial} section ${cell.sectionTheme} -- the capture would be ` +
            "an ordinary resting screenshot labelled as a hover one"
        );
      }

      for (const nodeId of forced) {
        await cdp.send("CSS.forcePseudoState", {
          nodeId,
          forcedPseudoClasses: [cell.pseudo],
        });
      }

      await element.screenshot({
        path: join(
          outputDir,
          `${state}-global-${theme}-${label}-${cell.note}.png`
        ),
      });

      for (const nodeId of forced) {
        await cdp.send("CSS.forcePseudoState", {
          nodeId,
          forcedPseudoClasses: [],
        });
      }

      process.stderr.write(`${state} ${theme} ${cell.note}\n`);
    }
  }
} finally {
  // Restore first, close second, but the close is in its own `finally` so a
  // throwing restore cannot skip it and leave the process hanging on the
  // browser's open handles. Same reasoning as 1614-collect-rendered.mjs.
  try {
    setGlobalTheme(originalTheme);
    process.stderr.write(`\nrestored global theme: ${originalTheme}\n`);
  } finally {
    await browser.close();
  }
}
