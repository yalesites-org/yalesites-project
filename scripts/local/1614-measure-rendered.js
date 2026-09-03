/**
 * Reads the RESOLVED colors of every FUNCTIONAL element out of a rendered
 * #1614 fixture page.
 *
 * Companion to #1613's `1613-measure-rendered.js`, which measured the section
 * itself. This one measures the three blocks #1613 deliberately left alone:
 * accordion, link_grid and wrapped_text_callout.
 *
 * Why a render and not the token computation in component-library-twig's
 * `section-background-contrast.mjs`: that script answers "which colors MAY sit
 * on which section background", a property of the palette. #1614 asks whether
 * a given element ACTUALLY resolves to a legible color, which depends on the
 * cascade -- specificity, inheritance, and custom properties that are invalid
 * at computed-value time. A token computation reports a broken cascade as
 * passing (that is exactly how #1614's accordion heading bug survived #1539).
 *
 * Each element is measured against its OWN painted background, not the
 * section's: a dialled accordion item paints its own gray-100 fill, so its
 * text sits on that rather than on the section color. Getting this wrong would
 * report the dialled accordion as failing on dark sections when it does not.
 *
 * ONE INTERACTION STATE PER CALL. Links are functional in three states and
 * #1614 AC #1 names all three, but `getComputedStyle` cannot be asked for a
 * pseudo-class -- there is no `getComputedStyle(node, ':hover')`. The caller
 * forces the state through CDP (`CSS.forcePseudoState`) and then calls this
 * with the state's name, so what is read back is the real resolved color out
 * of the full cascade rather than a rule scraped out of the CSSOM. The first
 * pass of this audit read only the resting color, which is why a hover pairing
 * below 4.5:1 could not appear in the table at all
 * (component-library-twig#714).
 *
 * A function EXPRESSION rather than an IIFE, because it now takes an argument:
 * 1614-collect-rendered.mjs wraps it in a call. Returns JSON to the caller.
 */
(state = "resting") => {
  /**
   * Functional elements per component.
   *
   * "Functional" is #1614's own word: block heading, item/section headings,
   * body text, links, expand/collapse icons, and any border or divider that
   * communicates state. The decorative option-six accent -- the accordion left
   * border, the callout outline, the link grid column rule -- is explicitly
   * out of scope, so it is recorded as `decorative: true` and reported for
   * completeness without being held to a threshold.
   */
  /**
   * The states a LINK is measured in.
   *
   * `:visited` is deliberately absent. It is a privacy-restricted pseudo-class:
   * a page cannot observe whether it matched, and `getComputedStyle` on a
   * visited link reports the unvisited color, so a measurement of it would be
   * a confident-looking copy of the resting row. The visited pairings are a
   * token question and belong with the palette math, not with this render.
   */
  const LINK_STATES = ["resting", "hover", "focus-visible"];

  const COMPONENTS = {
    accordion: {
      root: ".accordion",
      elements: [
        { name: "block heading", selector: ".accordion__heading" },
        { name: "expand-all control", selector: ".accordion__toggle-all" },
        {
          name: "expand-all icon",
          selector: ".accordion__toggle-all .accordion__icon",
          property: "fill",
        },
        { name: "item heading", selector: ".accordion-item__heading" },
        { name: "item toggle", selector: ".accordion-item__toggle" },
        {
          name: "item toggle icon",
          selector: ".accordion-item__icon",
          property: "fill",
        },
        { name: "item body text", selector: ".accordion-item__content p" },
        {
          name: "item body link",
          selector: ".accordion-item__content a",
          states: LINK_STATES,
        },
        {
          // Only drawn on the undialled accordion; the dialled one replaces it
          // with the decorative left accent. It separates one item from the
          // next, so it communicates structure rather than decoration.
          name: "item separator",
          selector: ".accordion-item",
          property: "borderBottomColor",
          nonText: true,
        },
        {
          name: "decorative left accent",
          selector: ".accordion-item",
          property: "borderLeftColor",
          decorative: true,
        },
      ],
    },
    link_grid: {
      root: ".link-grid",
      elements: [
        { name: "block heading", selector: ".link-grid__heading" },
        { name: "column heading", selector: ".link-group__heading" },
        {
          name: "link",
          selector: ".link-grid__link",
          states: LINK_STATES,
        },
        {
          name: "decorative column rule",
          selector: ".link-grid__column-wrapper",
          property: "borderLeftColor",
          decorative: true,
        },
      ],
    },
    wrapped_text_callout: {
      root: ".wrapped-callout",
      elements: [
        {
          // Heading LEVEL is an editor choice in the WYSIWYG, so the source
          // callout uses h3 while the SCSS styles h2. Level-agnostic here:
          // `_yds-headings.scss` gives every h1-h6 `color: var(--color-heading)`,
          // so whichever level the editor picked is the element to measure.
          name: "callout heading",
          selector: ".wrapped-callout__callout :is(h1, h2, h3, h4, h5, h6)",
        },
        { name: "callout body text", selector: ".wrapped-callout__callout p" },
        {
          name: "callout link",
          selector: ".wrapped-callout__callout a",
          states: LINK_STATES,
        },
        { name: "body text", selector: ".wrapped-callout__content p" },
        {
          name: "body link",
          selector: ".wrapped-callout__content a",
          states: LINK_STATES,
        },
        {
          name: "decorative callout outline",
          selector: ".wrapped-callout__callout",
          property: "borderTopColor",
          decorative: true,
        },
      ],
    },
  };

  /**
   * Which states an element is measured in.
   *
   * Everything that is not a link has exactly one, so the whole non-link half
   * of the sweep stays a single row per cell rather than tripling.
   */
  const statesFor = (element) => element.states || ["resting"];

  /**
   * The color actually painted behind an element.
   *
   * A transparent background means "whatever is painted behind me", so walk up
   * until something paints. Borrowed from 1613-measure-rendered.js; kept
   * per-element here rather than per-section for the reason in the file header.
   */
  const paintedBackground = (start) => {
    let el = start;
    while (el) {
      const bg = getComputedStyle(el).backgroundColor;
      if (bg && bg !== "rgba(0, 0, 0, 0)" && bg !== "transparent") return bg;
      el = el.parentElement;
    }
    return "rgb(255, 255, 255)";
  };

  const globalTheme = document
    .querySelector("[data-global-theme]")
    ?.getAttribute("data-global-theme");

  const rows = [];

  for (const section of document.querySelectorAll(
    ".yds-layout[data-component-theme]"
  )) {
    const sectionTheme = section.getAttribute("data-component-theme");

    for (const [component, spec] of Object.entries(COMPONENTS)) {
      const root = section.querySelector(spec.root);
      if (!root) continue;

      // Absent attribute reads as the `default` dial rather than as null.
      // A null would serialise into the JSON and then throw an opaque
      // TypeError out of `localeCompare` in the report generator -- in a
      // different repo from the one that produced the bad data.
      const dial = root.getAttribute("data-component-theme") ?? "default";

      for (const element of spec.elements) {
        // An element with no rule for this state is not measured in it. The
        // caller runs one pass per state, so skipping here is what keeps the
        // non-link elements from being recorded three times with identical
        // values -- which would read as three times the coverage.
        if (!statesFor(element).includes(state)) continue;

        // First match only: the fixture places one block per section and the
        // repeated elements (items, columns, links) all resolve identically,
        // so measuring every one would multiply rows without adding evidence.
        const node = root.querySelector(element.selector);
        if (!node) continue;

        const style = getComputedStyle(node);
        const property = element.property || "color";
        const value = style[property];

        // A zero-width border paints nothing, so its color is not a contrast
        // question. This is how the undialled accordion's absent left accent
        // and the "no lines" link grid drop out rather than reporting a
        // phantom failure.
        const widthProperty = {
          borderBottomColor: "borderBottomWidth",
          borderLeftColor: "borderLeftWidth",
          borderTopColor: "borderTopWidth",
        }[property];
        if (widthProperty && parseFloat(style[widthProperty]) === 0) continue;

        rows.push({
          globalTheme,
          sectionTheme,
          component,
          dial,
          element: element.name,
          state,
          property,
          value,
          background: paintedBackground(node),
          nonText: Boolean(element.nonText),
          decorative: Boolean(element.decorative),
        });
      }
    }
  }

  // The caller needs to know what was LOOKED FOR, not just what was found: an
  // element whose selector matches nothing produces no rows at all, which
  // reads as "no failures" rather than "not measured". See the coverage
  // assertion in 1614-collect-rendered.mjs.
  //
  // Keyed by state as well, so a link whose forced `:hover` pass matched
  // nothing is a hole in the audit rather than a quietly resting-only result.
  const expected = Object.entries(COMPONENTS).flatMap(([component, spec]) =>
    spec.elements
      .filter((element) => statesFor(element).includes(state))
      .map((element) => `${component} | ${element.name} | ${state}`)
  );

  // `states` is returned so the caller can assert its own pass list covers
  // every state an element declares. The two lists live in different files and
  // a mismatch is invisible to every other check here: a state in LINK_STATES
  // that the caller never runs produces no rows AND no expectation, and a state
  // the caller runs that no element declares produces an empty pass that
  // satisfies both coverage checks vacuously. Either way a state silently drops
  // out of the audit -- the same shape of hole as the resting-only measurement
  // this file was changed to fix.
  return { rows, expected, states: LINK_STATES };
};
