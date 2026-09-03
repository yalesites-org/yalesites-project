# Colour context research: does a component only need to look one container back?

Research for [YaleSites-Internal#1626](https://github.com/yalesites-org/YaleSites-Internal/issues/1626).

> **Follow-up tracking:** The recommendations in this document are scoped into
> [YaleSites-Internal#1634](https://github.com/yalesites-org/YaleSites-Internal/issues/1634)
> — _Epic: Color system, component surface model_. Child tickets
> [#1627](https://github.com/yalesites-org/YaleSites-Internal/issues/1627)–[#1632](https://github.com/yalesites-org/YaleSites-Internal/issues/1632)
> follow the phases in §5.4. §8 question 1 is the blocking decision and is tracked in
> [#1627](https://github.com/yalesites-org/YaleSites-Internal/issues/1627), along with the
> accessibility sign-off on removing the self-referential cycles. The token-value drift
> noted in §9 is a separate follow-up in
> [#1633](https://github.com/yalesites-org/YaleSites-Internal/issues/1633).

**This is a research document. No production behaviour changes with it.** The output is an
audit, a set of reproducible WCAG failures, and a recommendation for the team and the lead
developer to review before any implementation is scoped.

- **Date:** 2026-09-01
- **Measured against:** `yalesites-project` `develop` @ `5898452f` (this branch's base),
  `component-library-twig` `develop`, `tokens` `main`, `atomic` `develop`. Note the working
  checkout has since moved on — a re-check will not find those exact revisions, so pin to
  `5898452f` if reproducing.
- **Environment:** local Lando only. Nothing was run against, logged into, or changed on any
  remote or production site.

---

## 0. Disclosure: this audit was not blind, and how that was mitigated

The ticket asks for the component audit to be done cold and committed **before** opening a
held-back "Validation set" in a collapsed `<details>` block at the end of the issue body.

That is not achievable as written when the issue is read through the GitHub API.
`gh issue view --json body` returns the whole body as a single string; `<details>` is a
rendering affordance in the web UI, not a separate field, and cannot be excluded. The
validation set was therefore in context before any audit work started.

**Mitigation.** The independent audit pass was delegated to five parallel subagents, each
given a written brief describing the mechanism and the audit task **with the validation set
removed**. They had no access to the held-back cases. Their findings are a genuinely
independent pass. Findings attributable only to the coordinating author are marked, and
should be read with hindsight bias in mind.

**Process recommendation.** If the team wants a genuinely blind audit from an agent, the
held-back set has to live somewhere the agent is not handed up front: a separate issue, a
comment posted after the independent pass lands, or a file revealed on request. A `<details>`
block does not achieve it. This is worth fixing for future research tickets.

### 0.1 This document was adversarially fact-checked, and it changed the argument

After the first draft, an independent pass re-verified every `file:line` citation, every
specificity claim, and every contrast ratio against source. It found real errors. They are
corrected in place rather than hidden, and the substantive ones are listed here because two of
them change what the document concludes:

| what was wrong | corrected to |
|---|---|
| §3.1 claimed 0 of 46 direct-placement buttons fail, generalised to all themes | That page renders under **global theme one only**. Across all seven themes the same pairing **fails AA in 8 of 35 combinations** (2 also below 3:1). The control still localises the *severe* fault to nesting, but it does **not** exonerate the section pairings — see §3.1. |
| §7.1 claimed the #1533 contrast matrix covers only four global themes | **False.** It iterates the token map, so all seven already render. Its real blind spot is that it shows slot x slot pairings and does not reproduce nesting. |
| "16 self-referential cycles"; `_yds-layout.scss:51` listed as one | **20** exist; `:51` is a reference to a *different* cyclic property, not a self-reference; `:30`/`:31` are already dead. See §2.4 D3. |
| "38 dangling `--component-themes-{four,five}-slot-*` references" | **292** (146 per theme across slots one to eight). |
| `--color-link-grid-action` "declared only by `.yds-layout`" | Declared in **two** files; link-grid's own value normally wins, so it is not evidence of intentional deference. |
| Phase 0 asked to add theme five to `_yds-image.scss`'s exclusion list | `_yds-image.scss` **already handles** theme five; only `_yds-textfields.scss` needs it. |
| "at least eight components" trust `--color-text` | **Three** unconditionally; two more only when a dial is left unset; `quick-links` not at all. |
| "28 of 40 palette primitives drift" | **30 of 42.** |

Also corrected: the `--color-slot-white` typo's impact was overstated (it is masked by `color`
inheritance and only bites non-inherited consumers); §2.3's "same as anywhere else" is false under
global theme four, which swaps slots two and five inside the section; `tabs:83` resolves fine and
only `:82` is undefined; `sd.config.js:102` is `:101`; and §9 no longer claims full
reproducibility, since the §3.1 control page exists only in a local database.

**What survived unchanged: all of the arithmetic.** Every ratio in §2.2, §3.2, §3.3, §3.4 and
§3.7 reproduced exactly — 210 renders, 129 / 101 / 29 failures, the per-global split including
24/30 for theme seven, the 15 structural pairings, and the (0,5,0) specificity. The two root
causes and the reproduction stand as measured.

---

## 1. Answer to the ticket's question, up front

**Yes — a component should only look one container back, and the current system contains an
explicit, hardcoded rule that does the opposite.** That rule is the single largest source of
the contrast failures documented here.

The problem is *not* primarily what the ticket hypothesises (CSS custom properties inheriting
from a distant ancestor). Inheritance is involved, but the decisive mechanisms are two
specific, findable defects:

1. **An explicit section-scoped reach-back in the shared CTA mixin** (§2.1). Every button
   inside a themed Layout Builder section takes its colour from a variable only the *section*
   owns, at any nesting depth, at a specificity no component can override.
2. **One attribute, two incompatible meanings** (§2.2). `data-component-theme` means one set
   of slot mappings on a section and a *different* set on a block, and two variables
   (`--color-background`, `--color-text`) are only ever set using the block meaning — even on
   sections.

Both are narrower than "the whole cascade is wrong", and both are fixable without
re-architecting the design system. That is the good news in this report.

Measured on a real Drupal page: **129 of 210 real button renders (61%) fail WCAG 2.1 AA
SC 1.4.3, and 29 are effectively invisible** (§3).

---

## 2. The mechanism, in plain terms

### 2.0 How colour is carried

Three layers, outermost first. Only the bolded elements carry a colour attribute:

| # | Element | Attribute | Set by |
|---|---|---|---|
| 1 | `<body>` | — | — |
| 2 | **`div.yds-layout-container`** | **`data-global-theme`** (one..seven) | `_yds-base.twig:8-12`, value from `ys_themes.module:173-180` |
| 3 | `main`, Drupal field/LB wrappers | — | core |
| 4 | **`div.yds-layout.layout`** | **`data-component-theme`** (default, one..five) | `yds-layout.twig:19`, value from `layout--two-column--50-50.html.twig:23` |
| 5 | `.yds-layout__inner` / `__primary` / region | — | — |
| 6 | `div.ys-block-wrapper` | — | `atomic/.../_layout-builder-block-template.twig:10,16` |
| 7 | **`div.callouts`** (or any block) | **`data-component-theme`** (one..six) | e.g. `yds-callout.twig:28` |

**The component dial sits five elements below the section dial, and nothing in between
re-declares any colour variable.** `component-wrapper` and `block-wrapper` are fully
transparent pass-throughs — verified, zero colour declarations.

`data-global-theme` establishes `--color-slot-one` … `--color-slot-five` only
(`_color-global-themes.scss:6-13`). Slots six–nine exist **only** inside a subtree whose
component declares them — chiefly `.yds-layout`. Outside a section, `var(--color-slot-seven)`
is undefined.

Two CSS facts the rest of this document relies on:

- A declaration in a rule that **directly matches** an element always beats an **inherited**
  value from an ancestor, whatever the ancestor selector's specificity.
- `var(--x)` resolves against `--x` **on the element where it is used**, not where the
  referencing declaration was written.

### 2.1 Root cause A — the explicit reach-back (the smoking gun)

`components/01-atoms/controls/cta/_yds-cta.scss:333-348`, inside the shared `cta` mixin:

```scss
.yds-layout[data-component-theme]:not([data-component-theme='default']) & {
  &[data-cta-style='filled'] {
    --color-cta-text: var(--color-layout-theme);
    --color-cta-bg: var(--color-layout-border);
    ...
  }
  &[data-cta-style='outline'] {
    --color-cta-text: var(--color-layout-border);      // <-- SECTION-owned variable
    --color-cta-text-hover: var(--color-layout-theme); // <-- SECTION-owned variable
    --color-cta-border: var(--color-layout-border);
    --color-cta-bg-hover: var(--color-layout-border);
  }
}
```

`--color-layout-theme` (the section's painted background) and `--color-layout-border` are
declared **only** by `.yds-layout` — the per-theme blocks at `_yds-layout.scss:88-144`, plus the
unqualified defaults at `:12` and `:27`. Nothing else in the library declares either variable.
This rule therefore says, in
so many words: *any button anywhere inside a themed section is coloured for the section, not
for the surface it sits on.* It is a descendant selector, so depth is unbounded.

The compiled selector is
`.yds-layout[data-component-theme]:not([data-component-theme=default]) .callout__cta[data-cta-style=outline]`
— specificity **(0,5,0)**. No component-level rule in the library outranks it. The component
cannot opt out.

`--color-layout-border` per section theme (`_yds-layout.scss:88-144`):

| section theme | painted background (`--color-layout-theme`) | `--color-layout-border` -> **button text** |
|---|---|---|
| one | slot-1 | **slot-4** |
| two | slot-4 | **slot-7** |
| three | slot-5 | **slot-4** |
| four | slot-2 | **slot-4** |
| five | slot-9 | **slot-7** |

Under every global theme, slot-4 is a light colour and slot-7 a near-black. So:

- section themes **one / three / four** paint button text in a **light** colour -> any block
  with a light background inside them gets light-on-light;
- section themes **two / five** paint button text **near-black** -> any block with a dark
  background inside them gets dark-on-dark.

That prediction is exactly what §3 measures, which is the strongest evidence that this is the
operative mechanism rather than a contributing one.

`.cta-banner__cta` (`_yds-action-banner.scss:406-415`) has the same shape via
`--color-action: var(--color-banner-action, var(--color-slot-seven))` — a slot-seven fallback,
and slot-seven only exists because an ancestor `.yds-layout` declared it.

### 2.2 Root cause B — one attribute, two incompatible meanings

The generic rule, `components/00-tokens/colors/_color-component-themes.scss` (17 lines, whole file):

```scss
@each $theme, $value in $themes {
  [data-component-theme='#{$theme}'] {
    --color-slot-one ... --color-slot-five;   // slots 1-5 only
    --color-background: var(--component-themes-#{$theme}-background);
    --color-text:       var(--component-themes-#{$theme}-text);
    --color-heading:    var(--component-themes-#{$theme}-heading);
  }
}
```

This matches **any** element with the attribute — including `.yds-layout`. So a section gets
`--color-background` and `--color-text` from the **block-level** meaning of its theme value,
while painting itself using the **section-level** mapping in `_yds-layout.scss`. The layout
never re-declares those two variables, so they survive and inherit down.

Measured on a rendered section — inherited `--color-text` vs the background the section
actually paints:

| global theme | section theme two paints | inherited `--color-text` | ratio | SC 1.4.3 |
|---|---|---|---|---|
| one | `#f7f7f7` | `#ffffff` | **1.07:1** | FAIL |
| two | `#dfdcc8` | `#ffffff` | **1.38:1** | FAIL |
| three | `#e2c479` | `#ffffff` | **1.69:1** | FAIL |
| four | `#e8f1f7` | `#ffffff` | **1.14:1** | FAIL |
| five | `#f7f7f7` | `#ffffff` | **1.07:1** | FAIL |
| six | `#ffd65c` | `#ffffff` | **1.39:1** | FAIL |
| seven | `#c6baa9` | `#ffffff` | **1.90:1** | FAIL |

**Section theme two fails in all seven global themes**, and it is the only section theme that
does (the other four pass, 4.60:1 – 16.10:1). Any component that trusts `--color-text` **without
re-declaring it** renders near-invisible text in a theme-two section.

Being precise about who that is, because it is fewer components than it first appears:

| consumer | re-declares `--color-text`? | exposed? |
|---|---|---|
| `social-links:34` | no | **yes, unconditionally** |
| `event-meta:50,54` | no | **yes, unconditionally** |
| `publication-detail:22,209` | no | **yes, unconditionally** |
| `profile-meta:18` | yes (`:64,71,75,83,90,97,103`) | no |
| `text-with-image:265,269,293` | yes (`:86,96,108,124,132`) | only when the dial is left at `default` |
| `taxonomy-display:31` | yes (`:90,99,110,125,132`) | only when the dial is left at `default` |
| `quick-links:14` | yes (`:46,222`), and its dial always defaults to `one` | **no — not exposed at all** |

So: **three components unconditionally**, two more when an editor leaves the dial unset, and
`quick-links` not at all. The mechanism is real and worth fixing; the blast radius is narrower
than "every consumer of `--color-text`".

The same disagreement affects `--color-background`, whose most visible consumer is
`--color-text-shadow` — the halo knocked out behind link descenders
(`_yds-image.scss:30,61`). The per-theme fixes at `_yds-image.scss:44-66` cover component
themes one, three, four and five; **theme two is absent**, so a caption link in a theme-two
section paints a dark smudge on a light background. This is the same underlying fault as the
text-shadow halo bug tracked in #1551.

### 2.3 What is *not* the mechanism (correcting the ticket)

The ticket states the problem as a nested component keeping "the section's slots six to nine".
That is real but nearly harmless, and saying so matters because it changes what a fix must do.

Inside `.yds-layout`, two rules set slots at equal specificity **(0,2,0)**:
`.yds-layout[data-component-theme='X']` (`:16-54`) and `[data-global-theme='G'] .yds-layout`
(`:60-84`). The global one is later in source, so **it wins** — verified in the compiled
sheet. A section's slot values are therefore always the *global* theme's values, which are
also what any element elsewhere on the page would resolve, **with one exception**: under global
theme four, `_yds-layout.scss:78-82` swaps slot-two and slot-five *inside* the `.yds-layout`
subtree, and `_color-global-themes.scss` does not. So under that one theme a section's slot-two
and slot-five are the opposite of what an element outside a section resolves. Otherwise a nested
component inheriting slot-seven sees nothing unusual.

Consequences:

- `--component-themes-{one..five}-slot-*` are **dead code** wherever a global theme is
  present — which is always, in Drupal. Two token maps are maintained for no rendered effect.
  The same is true of `site-header-themes` and `site-footer-themes` slot values.
- **Storybook and production diverge.** A story rendered without a `[data-global-theme]`
  wrapper is the only place `component-themes` slot values are observable. Storybook is
  therefore not a faithful preview of section-nested colour, which undermines its use as the
  review surface for exactly this class of bug.

### 2.4 Three further defects found while tracing

**D1 — component themes four and five are voided, not inherited.** `tokens/tokens/base/color.yml`
nests slots under an extra `colors:` key for `component-themes` four (`:78-101`) and five
(`:102-125`), but not for one/two/three. The build therefore emits
`--component-themes-four-colors-slot-one` and **no** `--component-themes-four-slot-one`, while
every `@each` loop interpolates the flat name. Verified by count in the compiled CSS:

```
--component-themes-four-slot-one:  defined 0x, referenced 19x
--component-themes-five-slot-one:  defined 0x, referenced 19x
--component-themes-one-slot-one:   defined 1x, referenced 19x
```

(Those counts are for `slot-one` alone; across slots one to eight it is **146 dangling
references per theme, 292 in total**.)

A `var()` on an undefined property with no fallback is invalid at computed-value time, so
slots 1–5 are **erased**, not left inherited. Inside a section the global rule repairs it;
outside a section (site header, footer, in-this-section, and every Storybook story without a
global wrapper) it does not.

**D2 — `data-component-theme="six"` has no CSS rule at all.** `component_overrides.yml` offers
option `six` for callout, cta_banner, wrapped_text_callout, grand_hero, content_spotlight,
content_spotlight_portrait, tile and others, but `component-themes` in tokens defines only
one..five, and both generic loops iterate that map. `ColorTokenResolver` maps option six to
global slot-nine for the picker swatch, so **the editor sees a colour swatch that the CSS
never applies.**

**D3 — self-referential declarations that erase rather than preserve.** A custom property
referencing itself is a dependency cycle, so it computes to the guaranteed-invalid value and the
inherited value is *discarded* — the opposite of the evident intent. It happens to work for
`color` (an inherited property falls back to the parent's computed value) and would fail for
`background-color`, `border-color`, `fill` or `text-shadow`.

**20 such declarations exist** across `components/`, six of them in the section wrapper:

| file | lines | property |
|---|---|---|
| `_yds-layout.scss` | `:30`, `:42`, `:49` | `--color-link-base` |
| `_yds-layout.scss` | `:31`, `:41`, `:48` | `--color-link-hover` |
| 11 molecules/organisms | `reference-card:539`, `content-spotlight-portrait:52`, `inline-message:68`, `taxonomy-display:51`, `text-with-image:47`, `quote-callout:56`, `facts-and-figures-group:44`, `secondary-nav:93`, `site-in-this-section:46` | `--color-link-hover` |
| `tile-item` | `:103`, `:115` | `--color-link-visited-hover` |
| `pull-quote:33`, `quote-callout:48`, `:49` | | component-private accents |

Two details that matter for fixing them:

- **`_yds-layout.scss:51` is *not* a self-reference.** It is
  `--color-link-visited-hover: var(--color-link-hover)` — a reference to a *different* property
  that happens to be cyclic, so it inherits the invalidity. A related defect, but a different fix.
- **`:30` and `:31` are already dead**, overridden on `.yds-layout` itself by the later,
  same-specificity per-theme blocks at `:88-144`. The declarations that actually bite are the
  descendant rules at `:41-42` (`.link`, `.text-field a`, `.caption a`) and `:48-49`
  (`.link-grid__link`), which nothing re-declares.

**D4 — the developer documentation teaches the bug.** `docs/color-theme.md` — note this one file
lives in **`yalesites-project`**, not `component-library-twig` like every other path in this
document — "Working with
themes in CSS", Part three, shows the global-theme loop as:

```scss
@each $globalTheme, $value in $global-callout-themes {
  [data-global-theme='#{$globalTheme}'] & {
    ...
    --color-slot-six: var(--component-themes-#{$theme}-slot-six);   // WRONG: $theme
```

`$theme` is the *component*-theme loop variable, used inside the *global*-theme loop. The real
`_yds-callout.scss:50-57` is correct, so this is documentation-only — but it is the
copy-paste template developers are pointed at. Worth fixing regardless of this research.

---

## 3. Reproducible failures, with evidence

### 3.1 Calibration — the direct case is much better, but not clean

`/blocks-for-visreg/button`, a real page with CTAs placed **directly** in themed sections:
**0 of 46 buttons fail AA**, worst ratio 4.98:1.

**That page renders under global theme one only, and the result does not generalise.** For a
directly-placed CTA the reach-back resolves to `--color-layout-border` on `--color-layout-theme`,
so the pairing is computable for every theme. Across all 7 global x 5 section themes:

| | result |
|---|---|
| combinations | 35 |
| fail SC 1.4.3 (<4.5:1) | **8** |
| also fail 3:1 | **2** (global seven x section three at 2.42:1; x section four at 2.99:1) |

The failures cluster on section theme **four** (fails under globals two, three, four, six, seven)
and on global theme **seven** (fails on sections one, three, four). Under global theme one all
five section themes pass (4.98:1 – 15.03:1), which is why the visreg page shows nothing.

**What this control does and does not establish.** It localises the *severe* fault to nesting:
direct placement bottoms out at 2.42:1 and never produces an invisible control, whereas nesting
produces 129/210 failures and 29 renders at or near 1.00:1. But it does **not** rule out the
section pairings themselves being wrong — 8 of 35 fail AA on their own, worst under theme seven,
the palette this document elsewhere identifies as the weakest. **Those 8 are a separate defect
from root cause A and will not be fixed by fixing it.** They belong with the #1613/#1614
section-colour work.

### 3.2 The reproduction page

Built through the Layout Builder API (`build_repro.php`, in the run log): node 90,
*ISSUE 1626 color context repro* — 5 sections (theme one..five) x 6 Callout blocks
(background one..six) = 30 real callout CTAs, then measured under all seven global themes by
setting `data-global-theme` on `.yds-layout-container` (exactly what the sitewide setting
does).

Contrast computed with the library's own `components/00-tokens/colors/contrast-ratio.mjs`,
against the background actually **painted** behind each button (nearest ancestor with a
non-transparent background), not a nominal token value.

| metric | result |
|---|---|
| real CTA renders measured | **210** (30 blocks x 7 global themes) |
| fail **SC 1.4.3** normal text, 4.5:1 | **129 (61%)** |
| fail 3:1 — **SC 1.4.3** large text, **SC 1.4.11** non-text (the border) | **101 (48%)** |
| effectively invisible, <1.15:1 | **29** |

Per global theme (fails out of 30): one 15, two 18, three 21, four 18, five 15, six 18,
**seven 24**. Theme seven is the worst and is not covered by the existing contrast matrix.

### 3.3 The 15 pairings that fail in every global theme

Structural failures — independent of palette:

| section theme | fails with callout background | why |
|---|---|---|
| one, three, four | **two, four, six** | button text = slot-4 (light) on a light panel |
| two, five | **one, three, five** | button text = slot-7 (near-black) on a dark panel |

Exactly as §2.1 predicts from `--color-layout-border`.

### 3.4 Worst individual renders

| ratio | global | section | callout | text on background |
|---|---|---|---|---|
| **1.00:1** | one | one | two | `#f7f7f7` on `#f7f7f7` |
| **1.00:1** | one | two | three | `#212121` on `#212121` |
| **1.00:1** | one | three | two | `#f7f7f7` on `#f7f7f7` |
| **1.00:1** | one | four | two | `#f7f7f7` on `#f7f7f7` |
| **1.00:1** | one | five | three | `#212121` on `#212121` |
| **1.00:1** | two | one | two | `#dfdcc8` on `#dfdcc8` |
| **1.00:1** | three | one | two | `#e2c479` on `#e2c479` |
| **1.00:1** | four | one | two | `#e8f1f7` on `#e8f1f7` |
| 1.31:1 | one | one | six | `#f7f7f7` on `#d9d9d9` |
| 1.33:1 | one | two | one | `#212121` on `#00366b` |
| 2.29:1 | one | one | four | `#f7f7f7` on `#61a8ff` |

1.00:1 means the button label and border are **the same colour as the panel behind them**.

### 3.5 WCAG 2.1 criteria failed

For each documented pairing:

- **SC 1.4.3 Contrast (Minimum), Level AA** — button label is normal-size text, so 4.5:1.
  129 of 210 renders fail. At 1.00:1 the text is not perceivable at all.
- **SC 1.4.11 Non-text Contrast, Level AA** — the outline button's **border** is the visual
  boundary of a user-interface component and needs 3:1 against the adjacent background. It is
  set from the same `--color-layout-border`, so 101 of 210 fail. Where the ratio is 1.00:1 the
  control has no perceivable boundary: a sighted keyboard or mouse user cannot see that there
  is a button.
- **SC 1.4.1 Use of Colour** is *not* failed — colour is not the only means of conveying
  information here.

The `--color-text` failures in §2.2 are also **SC 1.4.3** (1.07:1 – 1.90:1).

### 3.6 Steps to reproduce, no code

1. Create a page. Add a **two-column 50/50** section; set its background colour to **one**.
2. Place a **Callout** block in it. Set the callout's background colour to **two**.
3. Give the callout a link so its CTA renders. Save and view as an anonymous user.
4. The "Learn more" button is invisible.
5. Inverse: section background **two**, callout background **one** — dark-on-dark.

Screenshot: `images/1626/section-theme-one-callout-backgrounds.png` (section theme one, all six
callout backgrounds, global theme one). The same image contains the failures and the working
control cases — callouts one, three and five render correctly on dark panels.

![Section theme one with six callout backgrounds. The Learn more button is invisible on callout
two, a faint ghost on callout six, and barely legible on callout four, while rendering correctly
on the dark callouts one, three and five.](images/1626/section-theme-one-callout-backgrounds.png)

> Note the section layouts that can be themed at all are only
> `ys_layout_two_column_50_50` and `ys_layout_three_column_33_33_33`. The 70/30 two-column,
> banner, page-meta and one-column layouts never emit `data-component-theme`, so components
> inside them read slots straight from the global theme and are unaffected.

### 3.7 A second, independent failure mode: headings inside `tabs`

Root cause A explains the buttons. A different mechanism produces the same class of failure for
headings, and it is worth stating separately because a fix aimed only at root cause A will not
catch it.

`_yds-tabs.scss:63,70,77` declares `--color-heading: var(--color-gray-700)` **directly on
`.tabs`**, so it defeats the section's inherited `--color-heading`, whichever slot that is: slot-eight for
section themes one, three and four (`_yds-layout.scss:96,118,130`) and slot-seven for the light
themes two and five (`:105,141`). Meanwhile `.tabs__container` sets **no background** (verified in the
compiled CSS), so tab-panel content renders on the *section's* background. gray-700 was chosen
for the gray-100 tab strip, not for the section behind the panel.

Measured over 7 global themes x 5 section themes, heading colour vs painted background:

| result | count |
|---|---|
| fail SC 1.4.3 (<4.5:1) | **21 / 35** |
| exactly 1.00:1 (heading invisible) | 2 (global two x section one; global six x section three) |
| range of failures | 1.00:1 – 1.92:1 |

Section themes two and five pass (4.64:1 – 8.27:1); one, three and four fail in **every** global
theme. `tabs__theme` is hard-wired to `'one'` in Drupal (`yds-tabs.twig:35`; the Atomic field
template passes no theme), so **every tabs block placed in a themed section is affected** — there
is no editor choice that avoids it.

The generalisable lesson: **a component that declares a foreground but paints no background is
always a latent failure**, because its foreground was chosen against a background it does not
control. `wrapped-callout` (dark heading plus a border that collides exactly with the section
background at 1.00:1) and `link-grid` (white heading scoped to its own dial, no background at
all) have the same shape. A "nearest container" model must therefore treat *painting a background* and
*choosing a foreground* as one indivisible decision.

---

## 4. Per-component audit

Produced by five parallel independent passes (see §0). Columns:

- **Source** — where the component reads colour from: `own dial` (its own `[data-component-theme]`
  block), `generic` (relies on the shared 17-line rule), `ancestor` (a selector shaped
  `[data-x] &`, i.e. it reads an *ancestor's* attribute), `global` (has its own
  `[data-global-theme] &` block), `inherit` (only consumes `var()`), `hardcoded` (raw palette
  token or literal).
- **Past parent?** — does colour reach it from beyond its immediate container?
  `NO` = properly bounded. `PARTIAL` = bounded for some variables, leaks others.
  `YES` = fully transparent to ancestors.
- **Impact of a nearest-container model** — `none`, `low`, `fix` (the model repairs a real
  defect here), `regress` (current behaviour is intentional and must be preserved explicitly).

### 4.1 Atoms (`01-atoms`)

| Component | Source | Re-scopes | Past parent? | Impact |
|---|---|---|---|---|
| `atoms.scss` | none (`@forward`) | – | – | none |
| audio | inherit | no | YES | fix — reads `--color-link-base` it never resets |
| controls/base | none | no | – | none |
| controls/button | inherit (`color: inherit`) | no | YES (intended) | none |
| **controls/cta** | own dial + global + ancestor + hardcoded | slots 1-8 at itself | **PARTIAL** | **fix — root cause A lives here (`:333-348`)** |
| controls/text-copy-button | global + inherit | slots 1-5 + `--color-link-hover` | PARTIAL | fix — pins hover from global slot-two on the element |
| **controls/text-link** | global + ancestor + inherit + hardcoded | slots 1-5, `--color-link-hover` | **PARTIAL** | **fix — never resets `--color-link-base` / `-visited-*`; hardcodes white halo at `:299,306`** |
| date-time | inherit | no | YES | none |
| divider | ancestor (`--color-layout-border`) | no | PARTIAL | fix — bare `[data-component-theme] &`, reads a section-only variable |
| forms (`_yds-form.scss`) | inherit + hardcoded | no | YES | fix — reads `--color-action`, never defines it |
| forms/checkbox, forms/radio | none | no | YES | none |
| forms/select | hardcoded only | no | YES (wrong) | fix — gray-700 text over a transparent control |
| forms/textfields | ancestor + hardcoded | no | PARTIAL | fix — light-theme exclusion list omits theme five |
| images/fa-icons, images/icons | inherit (`currentcolor`) | no | YES (correct) | none — reference pattern |
| images/image | ancestor + hardcoded | link vars for section theme five only | PARTIAL | fix — caption hover hardcoded gray-800 |
| lists | **hardcoded Sass literal** | no | n/a | **fix — `hsl(210,100%,21%)` baked in, unoverridable** |
| lists/taxonomy | markup only | no | n/a | fix (inherits the above) |
| tables | ancestor + hardcoded (bg *and* fg) | no | NO for cells, YES for `caption` | low — self-consistent but theme-blind |
| typography/headings | inherit (`--color-heading`) | no | **NO — correct** | none — reference pattern |
| typography/text | hardcoded (hljs pairs) | no | n/a | none — self-consistent pair |
| videos/video-background | own dial (on itself) + global | slots 1-5 | PARTIAL | fix — scrim undefined for themes four/five/six; `--color-backgound` typo |
| videos/video-embed | none | no | YES | none |

### 4.2 Molecules (`02-molecules`)

| Component | Source | Re-scopes | Past parent? | Impact |
|---|---|---|---|---|
| accordion | own dial + global + ancestor + hardcoded | slots 1-9 | PARTIAL | fix — forces white heading on light section theme five |
| **alert** | own private dial (`data-alert-type`) | no slots; own `--color-alert-*` | YES (intended) | **none — reference fix pattern, see §4.4** |
| banner/action (cta-banner) | own dial + global + ancestor | slots 1-9 | PARTIAL | fix — CTA hijacked by root cause A; `--color-backgound` typo |
| banner/grand-hero | own dial + global + ancestor | slots 1-9 | PARTIAL | low — renders in the unthemed banner region |
| banner/image | own dial + global + ancestor | slots 1-9 | PARTIAL | low — banner region |
| banner/video | none | no | YES | none |
| **callout** | own dial + global + ancestor + hardcoded + broken token | slots 1-9 | **PARTIAL** | **fix — the measured reproduction (§3); `--color-slot-white` typo at `:76`** |
| cards/custom-card | global + hardcoded | slots 1-5 only | YES for slots 6-9 | fix — hardcoded border + white halo |
| cards/directory-listing-card | hardcoded + ancestor + inherit | none | YES | fix — gray-500 meta on section background |
| cards/reference-card | global + ancestor + hardcoded | slots 1-8 | PARTIAL | fix — hardcoded eyebrow/overline/prefix |
| **content-spotlight-portrait** | own dial + global + ancestor | slots 1-9 | PARTIAL | **fix — reassert targets the wrapper, not the `<a>`** |
| embed | none (`currentColor` border) | no | YES | none |
| facts-and-figures | own dial + global + ancestor | slots 1-8 (no slot-9) | PARTIAL | fix — theme six silently renders as theme one |
| image (content-image) | none | no | YES | low |
| inline-message | own dial + global + ancestor | slots 1-9 | PARTIAL | low — self-consistent; `:81` reads the wrong ancestor |
| **link-grid** | global + own dial + ancestor + hardcoded | slots 1-5 + 9 only | **PARTIAL** | **fix — white heading, no background of its own** |
| link-group | ancestor + hardcoded + cross-component token | none | YES | fix — applies a footer-only variable; latent CTA trap |
| link-skip | hardcoded + inherit | no | YES | low |
| menu, menu-toggle, menu-in-this-section-toggle | inherit + ancestor | no | YES | low — safe only because their one consumer sets the vars |
| meta/basic-meta | hardcoded | no | NO | low |
| meta/event-meta | inherit + hardcoded | no | PARTIAL | low — unthemed banner region |
| meta/profile-meta | own dial + global + hardcoded | slots 1-5 + 9 | PARTIAL | fix — reads undefined slot-seven; works by accident |
| meta/publication-meta | inherit + hardcoded | no | PARTIAL | low |
| meta/resource-meta | inherit | no | YES | low — blockname'd, well insulated |
| modal | hardcoded | no | NO — self-contained | none (correct for an overlay) |
| page-title | inherit + hardcoded | no | YES | low |
| **pager** | inherit + hardcoded | no | **PARTIAL** | **fix — white-on-white hover (1.00:1)** |
| pull-quote | own dial + global + ancestor + hardcoded | slots 1-5 | PARTIAL (deliberate) | **regress** — intentionally defers to `--color-layout-border`; gap: section theme two |
| quick-links | own dial + global + ancestor + hardcoded | slots 1-8 | PARTIAL | fix — its CTAs hijacked by root cause A |
| quote-callout | own dial + global + hardcoded | slots 1-9 | PARTIAL | fix — `--color-quote-callout` undefined; halo overwritten by section |
| read-time | hardcoded | no | NO | none |
| **related-content** | inherit (`--color-heading`) | no | YES (intended, documented) | **none — reference pattern, see §4.4** |
| search-result | hardcoded Sass literal | no | NO | fix — `tokens.$color-blue-yale` unthemeable |
| social-links | inherit + ancestor | no | PARTIAL | fix — reads `--color-text` the section never sets |
| **tabs** | own dial + global + ancestor + hardcoded | slots 1-5 + 9 | **PARTIAL** | **fix — 21/35 heading failures, measured (§3.7)** |
| taxonomy-display | own dial + global | slots 1-9 | PARTIAL | low — well built; theme six ungenerated |
| text (text-field) | hardcoded halo + inherit | no | PARTIAL | fix — `a` descendants captured by the section |
| **text-with-image** | own dial + global + hardcoded | slots 1-9 | PARTIAL | **fix — overline 1.00:1 on its own background** |
| tile-item | own dial + global + nested global | slots 1-9 | **NO — best insulated** | low — two broken visited-link vars |
| video | none | no | YES | none |
| **wrapped-callout** | own dial + global | slots 1-9 | **PARTIAL** | **fix — dark heading + border colliding with the section (1.00:1)** |
| wrapped-image | hardcoded halo + inherit | no | PARTIAL | fix — white halo on dark sections |
| `molecules.scss` | none (`@forward`) | – | – | none — but it fixes tie-breaking source order |

### 4.3 Organisms and page layouts (`03-organisms`, `04-page-layouts`)

| Component | Source | Re-scopes | Past parent? | Impact |
|---|---|---|---|---|
| **layout/layout** | own dial + global + generic | slots **1-9** + 8 semantics | **PARTIAL** | **fix — the container that creates the leak** |
| layout/two-column | inherit | no | YES | low — `--color-divider` never themed |
| **component-wrapper** | none — zero colour declarations | no | **YES — transparent** | fix — establishes no boundary |
| **block-wrapper** | none — zero colour declarations | no | **YES — transparent** | fix — every LB block is a bare inheritor |
| facts-and-figures-group | own dial + global | slots 1-9 | PARTIAL | fix — theme six gets nothing |
| site-header | private dial `data-header-theme` + global | slots 1-8 + 9 semantics | PARTIAL | low — most complete reset in the library |
| site-footer | private dial `data-footer-theme` + global | slots 1-8 | PARTIAL | low |
| site-in-this-section | own dial + global | slots 1-8 | PARTIAL | low |
| **menu/secondary-nav** | **ancestor** `[data-component-theme] &` | slots 1-8 | **PARTIAL / dangerous** | **fix — highest-numbered matching theme wins, not the nearest** |
| menu/primary-nav | hardcoded `:root` + bare attribute | no slots | YES | fix — `:root` palette defaults, flagged `@TODO` in-file |
| menu/utility-nav | ancestor `[data-header-theme] &` | none | YES | low |
| menu/breadcrumbs | inherit + hardcoded | no | YES | low |
| **calendar** | **hardcoded throughout — no dial, no slots** | no | YES | **fix — gray-800 on gray-800 in section theme three** |
| card-collection, custom-card-collection | none | no | YES | low |
| tiles | none | no | YES | low — delegates to `tile-item` |
| galleries/media-grid | hardcoded + variation attribute | no | YES | low |
| galleries/media-grid (modal) | private dial `data-basic-theme` | background/text/heading/halo/link | **PARTIAL — closest thing to a real boundary** | low — good example |
| `_grid-mixins`, `_list-mixins`, `organisms.scss` | none / inherit | – | – | none — `organisms.scss` fixes tie-break order |
| `04-page-layouts` (`_yds-base.twig`) | **origin of `data-global-theme`** | slots 1-5 site-wide | n/a — outermost | none — the lever, keep as is |
| `04-page-layouts/placeholder` | none | no | YES | none |

### 4.4 Two components already solve this correctly — copy them

The library contains its own answer twice, arrived at independently, each with a comment
explaining the reasoning. Any implementation should start from these rather than invent a
pattern:

- **`02-molecules/alert/_yds-alert.scss:154-167`** — documents exactly this bug class (a mixin's
  `[data-global-theme] &` block landing on the link element and beating an inherited value) and
  fixes it by re-asserting at winning specificity with `currentcolor`. The comment at `:161-163`
  records why `var(--color-alert-text)` was rejected.
- **`02-molecules/related-content/_yds-related-content.scss:77-96`** — explains why it reads
  inherited `--color-heading` rather than `--color-text`, because the former stays contrast-safe
  across global x section combinations and the latter does not.

Three more in-repo acknowledgements of the same mechanism exist as comments:
`_yds-grand-hero.scss:337-343`, `yds-image-banner.scss:208-224`, and
`_yds-reference-card.scss:539`. **The team has hit this five separate times and fixed it
locally each time.** That is the strongest available argument for a shared mechanism rather
than a sixth local patch.

### 4.5 Does anything fully reset the colour context?

**No.** Not one component resets slots 1-9 *and* all derived semantics at its own boundary.
`site-header` is the most complete (8 slots + 9 semantics, but no slot-nine, no
`--color-link-visited-*`, no `--color-cta-*`). `layout/layout` reaches 9 slots + 8 semantics but
never sets `--color-text`, `--color-background` or `--color-action*`. The generic dial resets 5
slots + 3 semantics. **Every component therefore renders in a hybrid colour context assembled
from whichever ancestors happened to declare each variable** — which is the finding that most
directly answers research question 1.

---

## 5. What a "nearest meaningful container only" model looks like

### 5.1 The concept the system is missing

There is no notion of **a surface**. Components paint backgrounds and components choose
foregrounds, but nothing records *what was painted* in a way the next element down can read.
Everything downstream re-derives a foreground from raw slots or from a distant ancestor's
semantic variable.

Everything in §2 follows from that single absence. So the model is one idea:

> Every component that paints a background must publish what it painted. Everything inside
> derives its foreground from that published value and from nothing else.

Concretely, two variables form the contract:

```
--surface-bg   the colour actually painted by the nearest enclosing surface
--surface-fg   the approved foreground for that background
```

A component that paints re-declares both **on its own wrapper**. Because a directly-matching
declaration always beats an inherited value, re-declaring at each boundary genuinely stops the
leak — this is the property that makes a CSS-only approach viable at all.

### 5.2 Why the global theme lever keeps working — and why that is not a coincidence

Research question 5 asks how this interacts with the sitewide lever, which genuinely should
cascade. The answer is clean, and §2.3 already proved the shape of it:

**The global theme is the palette. A surface chooses which member of the palette it paints.
A foreground is chosen relative to the surface.**

Those are three separate jobs, and the global theme only ever does the first. Today's system
already behaves this way for slots — a section's slot values *are* the global theme's values.
So the lever needs no change at all: it keeps setting slots site-wide, and surfaces keep
selecting from them. Nothing in this model asks the lever to stop cascading.

This is the strongest argument for the model: it does not fight the existing architecture, it
names a layer the architecture already implies but never wrote down.

### 5.3 The options, with trade-offs

#### Option A — CSS-only surface contract

Add the two surface variables. Extend the generic dial to reset the **whole** derived set, not
just three variables. Delete or re-scope the section-descendant rules.

The generic dial becomes, in effect:

```scss
[data-component-theme='#{$theme}'] {
  // palette (unchanged)
  --color-slot-one ... --color-slot-five;
  // the surface this component paints
  --surface-bg: ...;
  --surface-fg: ...;
  // reset EVERY derived variable so none can arrive from an ancestor
  --color-background: var(--surface-bg);
  --color-text: var(--surface-fg);
  --color-heading: var(--surface-fg);
  --color-link-base: ...;  --color-link-hover: ...;
  --color-link-visited-base: ...;  --color-link-visited-hover: ...;
  --color-action: ...;  --color-action-secondary: ...;
  --color-text-shadow: var(--surface-bg);
}
```

- **Pros.** No Twig or PHP changes. Genuinely incremental — one component at a time, each
  independently shippable and reviewable. Uses only mechanisms the team already uses. Fixes
  root cause B directly.
- **Cons.** Correctness depends on discipline: a new component that forgets to reset
  re-introduces the leak silently, which is precisely how we got here. **Needs an automated
  guard to be trustworthy** (see Option C). Does not touch hardcoded colours.
- **Effort.** Small-to-medium. The generic dial is a 17-line file; the expensive part is the
  ~22 components that emit a dial and the per-component derived variables they each invented.

#### Option B — a shared Twig helper that emits the surface

A macro every component calls to open a surface:

```twig
{% import "@tokens/colors/surface.twig" as surface %}
<div {{ surface.open(callout__background_color) }}> ... </div>
```

emitting the attribute plus the boundary, so participation is visible in the template and
greppable in review. A component that does not call it visibly does not participate.

- **Pros.** Makes the contract explicit and enforceable at the template layer, and
  self-documenting for the next developer. The natural vehicle is the SDC migration (#1351),
  where every template is being rewritten anyway — the marginal cost there is close to zero.
- **Cons.** Twig cannot compute contrast, so this **does not replace the CSS layer** — it only
  makes the boundary declarative. Touching every template outside the SDC migration is a large,
  low-reward diff. Does nothing for the CSS-only consumers (the `cta`, `link` and `atoms`
  mixins), which is where root cause A lives.
- **Effort.** Large standalone; near-free if folded into #1351.

#### Option C — build-time contrast gate (the highest-leverage piece)

Encode approved (surface, foreground) pairings in tokens, generate the CSS from them, and
**fail the build** when any generated pairing falls below 4.5:1. The repository already has
everything needed: `components/00-tokens/colors/contrast-ratio.mjs` implements WCAG 2.1
relative luminance and contrast ratio, it already has tests under `node --test`, and
`contrast-matrix.mdx` already renders pairings.

- **Pros.** Converts contrast from a review responsibility into a build gate. It is the only
  option that would have caught all 129 failures, and it keeps catching them as themes are
  added. The existing contrast matrix already covers all seven themes but cannot see *which*
  pairings components actually produce — closing that gap is precisely what a generated-pairing
  gate does. Extends the slot-1/6/7 safe-foreground rule from #1539 from a convention into an
  enforced invariant.
- **Cons.** Requires the token structure to express pairings, which is the largest upfront
  design work. Gates only the *generated* pairings — it cannot see a hardcoded literal like
  `.taxonomy-list--tags`, so it needs a companion lint banning Sass colour literals and raw
  palette tokens in foreground position.
- **Effort.** Medium. Mostly design, not code, because the math already exists and is tested.

### 5.4 Recommendation

**A hybrid, sequenced: Option A for the mechanism, Option C to make it stick, Option B folded
into #1351 rather than done separately.**

The sequencing matters more than the choice, because **most of the measured damage is not
architectural.** Phase 0 below is ordinary bug-fixing that requires no decision from this
research at all, and it removes the large majority of the 129 failures. It should not wait for
the model discussion.

**Phase 0 — fix the outright defects (small, do this regardless).**

| fix | where | why |
|---|---|---|
| Delete or re-scope the section-descendant CTA block | `_yds-cta.scss:333-348` | Root cause A. Removes the reach-back entirely. Needs a decision on what a button on a *bare* section should do — see §5.5. |
| Publish `--component-themes-{four,five}-slot-*` under the flat name | `tokens/tokens/base/color.yml:78-125` | 292 dangling references (146 per theme across slots 1-8); themes four and five currently void slots 1-5 |
| Add a `[data-component-theme='six']` block, or stop offering option six | tokens + `component_overrides.yml` | Editors pick a swatch the CSS never applies |
| Fix `--color-slot-white` -> `--color-basic-white` | `_yds-callout.scss:76` | `--color-slot-white` is defined nowhere, so `--color-link-base` is guaranteed-invalid. Currently masked: `color` is inherited, so it falls back to `.callouts { color: var(--color-text) }` = white, the intended value. The observable defect is confined to non-inherited consumers such as `fill: var(--color-link-base)` (`_yds-text-link.scss:43`), which falls back to black. Low severity, trivial fix. |
| Give `--color-action` a real root fallback | `_global-config.scss:6` | Both referenced variables are undefined; an outline CTA can lose its border entirely |
| Remove the 20 self-referential `var()` cycles | `_yds-layout.scss:30,31,41,42,48,49` + 14 others (§2.4 D3) | They erase the value they appear to preserve. Start with `:41-42` and `:48-49` — the descendant rules nothing re-declares; `:30`/`:31` are already dead. Handle `:51` separately: it references a different cyclic property rather than itself. |
| Replace the Sass colour literal | `_yds-list.scss:67` | `hsl(210,100%,21%)` is baked into the compiled CSS and unoverridable; invisible tags on section theme one |
| Add section theme five to the light-theme exclusion list | `_yds-textfields.scss:34-42` | Its `:not(default, two)` guard omits five, so the required-field asterisk goes white on a light theme-five section. (`_yds-image.scss` does **not** need this — `:44-49` already excludes both two and five, and `:66-82` is a dedicated theme-five block.) |
| Fix the global-loop example | `docs/color-theme.md` Part three | Teaches `$theme` inside the `$globalTheme` loop |

**Phase 1 — split the overloaded attribute (small, unblocks everything).**
Emit `data-section-theme` from `yds-layout.twig` and leave `data-component-theme` to blocks.
One attribute cannot carry two incompatible slot mappings; while it does, no reset can be
correct for both. This is the single change that makes root cause B *impossible* rather than
patched. Also makes `.yds-layout[data-component-theme]` descendant selectors easy to find.

**Phase 2 — the surface contract (Option A), component by component.**
Order by measured risk: the four components that both re-scope and contain a CTA
(`callout`, `action-banner`, `content-spotlight-portrait`, `text-with-image`), then the
components that read `--color-text` without re-declaring it (§2.2), then the rest.

**Phase 3 — the build-time gate (Option C)**, once Phase 2 has a stable variable set to
generate from. Add the Sass-literal / raw-palette lint at the same time.

**Phase 4 — the Twig helper (Option B) inside #1351**, not before.

### 5.5 What would regress, and what is intentional

Research question 4. Three current behaviours are load-bearing and must be preserved
deliberately, not by accident:

1. **A button on a bare themed section must still be readable.** Root cause A's rule is
   wrong for *nested* buttons but right for buttons placed directly in a section — that is why
   §3.1 measures 0 failures there. Deleting the rule without replacing it would break the
   working case. The correct replacement is the surface contract: the section publishes its
   surface, the button reads the *nearest* surface, and for a directly-placed button the
   nearest surface *is* the section. Same outcome, no reach-back.
2. **`link-grid` partly wants the section's content colour — but less than first appears.**
   `_yds-layout.scss:148-153` forces dark headings on light section themes two and five, and that
   *is* a deliberate cross-boundary decision. But `--color-link-grid-action` is **not**
   section-only: it is declared in two files — `_yds-layout.scss:97,107,119,131,143` and
   `_yds-link-grid.scss:51,55,59,63,67,71,107`. Since `.link-grid[data-component-theme='one']`
   (0,2,0) directly matches the element while the section's value arrives only by inheritance,
   **link-grid's own value normally wins**, so this variable is not evidence of intentional
   deference. Likewise `_yds-link-grid.scss:91-93` carries a comment saying the white-heading
   rule is scoped to link-grid's own dial *precisely so the section cannot force it*. Treat the
   `:148-153` heading override as the genuine opt-in case and re-examine the rest rather than
   assuming deference.
3. **The global theme must keep cascading.** Covered in §5.2: it is the palette layer and is
   untouched.

One behaviour that looks intentional and is not: links and body text render the *same* colour
inside every themed section, because the cyclic declarations (§2.4 D3) erase the link colour
and `color` falls back to the inherited body colour. Removing the cycles will make links visibly
different from body text inside themed sections — a **visual change on existing sites** that
needs design sign-off before Phase 0 ships. It is also arguably a current **SC 1.4.1** issue,
since inside a themed section link text is distinguished from body text by underline only.

### 5.6 Can this be delivered incrementally?

Research question 6: **yes, and it should be.** Phase 0 is a set of independent bug fixes.
Phase 1 is one template plus a find-and-replace of section-scoped selectors. Phase 2 is
per-component and each component is independently shippable and reviewable. Only Phase 3
wants a stable variable set first.

**It does not belong inside #1351.** The SDC migration will rewrite every template, so folding
this in would mean a behavioural colour change and a structural template change landing in the
same diff, on the same components, with no way to bisect a regression. Do Phase 0 and Phase 1
*before* #1351 reaches these components, take the Twig helper (Option B) *with* #1351, and keep
Phase 2 and Phase 3 on their own track.

### 5.7 Accessibility upside

Research question 7. Fixing root cause A alone addresses the **129 of 210** measured AA
failures on real markup (§3), including **29 renders where the control is invisible**. Fixing
root cause B addresses the `--color-text` failures in every theme-two section (§2.2), which
affect three components unconditionally and two more whenever a block dial is left unset.
Phase 3 prevents the class from recurring as themes are
added — theme seven, the worst-performing palette at 24/30 failures, was added after the
existing contrast tooling was built and is not covered by it.

Because editors choose section and block colours independently from two dropdowns with no
cross-validation, and because the failures are structural rather than palette-specific
(§3.3), **the current system can produce an invisible button through two ordinary, individually
reasonable editor choices.** That is the accessibility argument for treating this as more than
a styling defect.

---

## 6. Reconciliation against the held-back validation set

Read only after §1–§5 were written. Per §0 the independent passes were genuinely blind; the
coordinating author was not, so this section states what the *audit* produced rather than
claiming personal blindness.

The validation set contains **one** item.

### Item 1 — "Action Banner button on a coloured section"

> Place an Action Banner inside a Layout Builder section that has a background colour set. The
> button inside the Action Banner picks up its colour from the section instead of from the Action
> Banner's own background. [...] Check the button label and fill contrast against the actual
> banner background across the global themes, and note where it fails WCAG 2.1 AA.

**Verdict: FOUND — independently, by three of the five audit passes, and identified as the
primary root cause of the whole ticket.**

| where it was found | what was reported |
|---|---|
| atoms pass | `_yds-cta.scss:333-348` catalogued as an `ancestor attribute` source on the CTA atom; noted that the section rule at (0,4,0)/(0,5,0) overrides the CTA's own `data-cta-theme` dial unconditionally (its C7) |
| molecules pass 1 | its **A1**, ranked HIGH, naming `.cta-banner__cta` explicitly alongside `.callout__cta`; computed 105 of 180 global x section x component combinations below 4.5:1, with a 1.00:1 set enumerated |
| molecules pass 2 | its **A5**, same rule reached via `quick-links`; confirmed the (0,5,0) specificity beats the component's own remediation |
| organisms pass | inventoried `--color-layout-theme` / `--color-layout-border` as declared **only** by `.yds-layout` and never re-declared by any molecule — the precondition that makes the reach-back bite |

The mechanism is documented in §2.1 as **root cause A**, and it is the rule this research
recommends deleting first (§5.4, Phase 0).

**Where the audit went beyond the validation item:**

1. **It is not specific to Action Banner.** The rule is in the shared `cta` mixin, so it applies
   to *every* CTA inside a themed section at any depth. `callout`, `quick-links`,
   `content-spotlight-portrait` and `text-with-image` are affected identically. Fixing only the
   Action Banner would leave the class open.
2. **The reproduction was built on `callout` rather than Action Banner**, because `callout` is
   reachable in the 50/50 section that can actually be themed, whereas Action Banner commonly
   renders in the banner region, which carries no theme (`layout--banner.html.twig` emits no
   `data-component-theme`). This is worth flagging back to whoever wrote the validation item:
   **the Action Banner case may be less reachable in production than the Callout case**, and the
   Callout case is measurably worse.
3. **Quantified across all seven global themes**, not four: 129 of 210 real renders fail
   (§3.2), with 15 pairings failing in every global theme (§3.3).
4. **SC 1.4.11 is also failed, not just SC 1.4.3.** For an outline button the *border* is the
   control's only visual boundary and is set from the same variable, so at 1.00:1 there is no
   perceivable button at all — a stronger finding than a text-contrast failure alone.
5. **A second, independent mechanism was found** that the validation set does not mention:
   `--color-background` / `--color-text` carrying block-level semantics on a section
   (§2.2, root cause B), plus the `tabs` / `wrapped-callout` / `link-grid` class of
   "declares a foreground, paints no background" (§3.7).

**Nothing in the validation set was missed, and no item is disputed.**

---

## 7. Coordination with existing epics

### 7.1 Section Colour epic — #1616, #1613, #1614, #1539, #1533, #1551

**This research supersedes the mechanism-level parts of #1613 and explains #1614 and #1551.**

| ticket | relationship | recommendation |
|---|---|---|
| **#1613** — "block dial inside themed section slot mixing"; "make slots six and up referenceable outside `yds-layout`" | Both acceptance criteria are **restatements of findings here**. The first is root cause B plus the reach-back. The second is confirmed as real: slots 6-9 exist only inside a `.yds-layout` subtree (§2.0), so `var(--color-slot-seven)` outside one is guaranteed-invalid — which is what silently disables `profile-meta:75-76,103-105` and `tabs:82`. (Not
`tabs:83` — `_yds-tabs.scss:42` sets `--color-slot-nine` inside tabs' own global-theme loop, so
only the slot-seven reference at `:82` is undefined.) | **Defer the "slot mixing" criterion** pending the Phase 0/1 decision — fixing it tactically means patching a symptom of the reach-back. **Proceed with "slots six and up referenceable"** immediately; it is independently correct, low-risk, and a prerequisite for the surface contract. |
| **#1614** — Accordion, Wrapped Callout, Link Grid contrast failures | All three appear in this audit as instances of one pattern, not three bugs: accordion forces white on light section theme five; wrapped-callout declares a dark heading and a colliding border while painting no background; link-grid sets a white heading — scoped to its own dial, deliberately, per the comment at
`_yds-link-grid.scss:91-93` — while painting no background at all, so that heading lands on the
section's surface. | **Proceed** — these are real and users are affected now. But land them knowing they are symptom fixes, and **record them as the third, fourth and fifth local patch** for the same root cause (§4.4 lists five prior ones). If #1614 is fixed the same way again, that is eight. |
| **#1539** — slot-1 / slot-6 / slot-7 safe-foreground rule | The right idea, applied as a convention. | **Promote it to the enforced invariant** in Phase 3 (§5.3 Option C). Its rule is precisely what the build-time gate should check. |
| **#1533** / component-library-twig#686 — contrast-matrix Storybook story | Better than this research first assumed: it iterates the token map (`colors.stories.js:207`, `:1216-1232`), so **all seven global themes already render** and a new theme appears automatically — `contrast-matrix.mdx:12` says so explicitly. Its real blind spot is structural: it shows **slot x slot** pairings without knowing which pairings components actually produce, and **it does not reproduce production nesting** — a story without a `[data-global-theme]` wrapper renders the otherwise-dead `component-themes` slot values (§2.3), so Storybook is not a faithful preview of section-nested colour. | Add a **nested fixture** (section theme x block theme) so the matrix can see this bug class at all. No theme-coverage work needed. |
| **#1551** — text-shadow halo | **Explained by root cause B.** `--color-text-shadow` is set from `--color-background`, which on a section disagrees with the painted background; and `_yds-image.scss:44-66`'s per-theme fixes omit section theme two, the one theme that always fails. | **Link #1551 to this research.** It is not a separate bug and a local fix will not generalise. |

### 7.1a Reconciliation with the in-flight #1613 work (PR yalesites-project#1514)

That PR is **open, based on `1616-section-color-parity`, not `develop`**, so none of it is in the
baseline measured here (verified: `--color-section-foreground` and
`section-background-contrast.mjs` are absent from `develop`). It reaches a conclusion that looks
like it contradicts this research, and the reconciliation is worth stating because it is the
strongest single piece of corroboration in this document.

#1514 computes safe foreground slots for every section background across all seven global themes
and finds **the intersection is empty** — no fixed slot is legible on every section background.
It concludes: *"a block has to consume the foreground the section supplies rather than name a
slot."* This research concludes a component should read the surface it is *actually on*, which for
a background-painting component is **not** the section.

**Both are correct, and they are the same rule.** The discriminator is whether the component
paints a background:

| component paints its own background? | nearest surface is | correct foreground source |
|---|---|---|
| **No** (link-grid, wrapped-callout, tabs panel, most cards, dividers) | the **section** | the section's foreground — exactly #1613's conclusion |
| **Yes** (callout, action-banner, quick-links, tile-item, content-spotlight-portrait) | **itself** | its own foreground — this research's conclusion |

"Look one container back" resolves to #1613's answer in the first row and to this document's
answer in the second. That is precisely why the model has to be stated in terms of *surfaces*
rather than in terms of sections or components (§5.1), and why §3.7's rule — *painting a
background and choosing a foreground are one indivisible decision* — is the operative test.

Two consequences:

1. **#1514's `--color-section-foreground`, declared only on `.yds-layout` and read with the
   component's previous colour as a CSS fallback, is already the surface contract in miniature.**
   It should be generalised into `--surface-fg` rather than reinvented — same mechanism, one more
   publisher (any component that paints a background), one renamed variable.
2. **#1514 also fixes `--color-divider`,** which this audit independently flagged as never themed
   (`_global-config.scss:8`, consumed by `two-column:58,64` and `_list-mixins.scss:17`). That
   confirms the two efforts are finding the same defects from opposite directions.

**Recommendation: do not block #1514.** It is the right change for the row-one case and it
establishes the mechanism. Ask only that the variable be named for the *surface* rather than the
*section*, so the row-two case can publish it too without a second rename later.

**Net:** #1614 should ship (users are affected today). #1514 should ship, ideally with the
surface-oriented name. #1613's "block dial inside themed section slot mixing" criterion should
wait for the root-cause-A decision (§8, question 1). Everything else feeds in rather than
conflicts.

### 7.2 SDC migration — #1351

**Recommendation: do not fold the mechanism change into #1351. Do fold the Twig helper in.**

- A behavioural colour change and a structural template rewrite landing in one diff, on the same
  components, gives no way to bisect a regression. These are colour changes with **visible
  effects on live sites** (§5.5), so they need to be independently revertable.
- Sequencing: land **Phase 0** (bug fixes) and **Phase 1** (split the attribute) *before* #1351
  reaches these components — both are small and neither conflicts with an SDC rewrite.
- **Phase 2** (the CSS surface contract) is per-component and can run in parallel with #1351 on
  components it has not yet reached.
- **Option B (the shared Twig helper) belongs inside #1351.** Every template is being rewritten
  there anyway, so the marginal cost of adding a `surface.open()` call is close to zero, whereas
  as a standalone change it is a large, low-reward diff.
- One SDC-specific caution: SDC components declare props in a schema, which makes it tempting to
  pass a resolved colour *value* into a component. **Do not.** The whole point of the surface
  contract is that a component reads the surface it is on at render time; passing a value down
  re-creates the current problem in a new form, with the extra cost that the value is now fixed
  at the point of inclusion.

---

## 8. Open questions for the review checkpoint

For the lead developer and the accessibility engineer. Most consequential first.

1. **Is the reach-back at `_yds-cta.scss:333-348` intentional?** Everything else depends on the
   answer. If it was a deliberate choice to make buttons match the section, then the correct fix
   is narrower (scope it to direct children) and the model discussion changes shape. If it was
   an expedient fix for the *direct*-placement case, delete it and replace with the surface
   contract. **Nothing else should be built until this is settled.**
2. **Removing the self-referential cycles (§2.4 D3) is a visible change on live sites.** Today
   links and body text render the *same* colour inside every themed section, because the cycles
   erase the link colour and `color` falls back to the inherited body colour. Removing them makes
   links visibly different. That needs design sign-off, and it is arguably a current **SC 1.4.1**
   issue (inside a themed section link text is distinguished from body text by underline only) —
   **a question for the accessibility engineer specifically.**
3. **Should `data-component-theme` option `six` exist?** It is offered by the editor for at least
   eight component types, the colour picker paints a swatch for it, and the CSS has no rule for
   it. Either add the token entry or remove the option — but it should not stay as it is.
4. **Should `component-themes` tokens exist at all?** Given they are dead wherever a global theme
   is present (§2.3), they are two token maps maintained for no rendered effect, and they are the
   reason Storybook diverges from production. Deleting them would simplify the system
   considerably — but would change every Storybook story's appearance.
5. **Is `pull-quote`'s deliberate deference to the section correct?** It explicitly discards the
   editor's accent choice in favour of `--color-layout-border` (`_yds-pull-quote.scss:75-77`).
   That is the *opposite* of a nearest-container model. If it is right for pull-quote, the model
   needs an explicit opt-in — which is also what `link-grid` needs (§5.5).
6. **How should section layouts that cannot be themed behave?** Four of six layouts never emit
   `data-component-theme` (§3.6 note), so slots 6-9 are undefined inside them and components
   referencing those slots silently lose colour. Should the surface contract define a default
   surface for unthemed sections?
7. **Where should the build-time contrast gate fail?** In `component-library-twig` CI (fast, but
   cannot see Drupal-side pairings) or in `yalesites-project` CI (complete, but slow and late)?
   Recommendation: CLT, since the pairings are a design-system property.
8. **Process:** the blind-audit requirement could not be satisfied as written (§0). If future
   research tickets want a genuinely blind pass from an agent, the held-back set needs to live
   outside the issue body.

---

## 9. Reproducing this research

Mostly scripted and re-runnable — with two honest gaps, stated first:

- **`/blocks-for-visreg/button` (the §3.1 control) exists only in a local database.** It has no
  source in `yalesites-project`, `component-library-twig`, `atomic`, or the starterkit
  migrations. Its 46-button result is therefore not independently reproducible. The *derived*
  part of §3.1 — the 8-of-35 direct-placement table — is reproducible from tokens alone and does
  not depend on that page.
- **Node 90 is local database state**, created by `build_repro.php`. The script is re-runnable
  against any local site; the node itself is not a committed artifact.

The contrast maths *is* fully re-runnable from the repo (`contrast-ratio.mjs` plus
`contrast-ratio.test.mjs` under `node --test`), and every number derived from tokens rather than
from local content reproduces. Artifacts referenced above:

| what | how |
|---|---|
| Reproduction page | node 90 on the local Lando site, *ISSUE 1626 color context repro*; built by `build_repro.php`, idempotent — re-running deletes and rebuilds it |
| Contrast maths | `component-library-twig/components/00-tokens/colors/contrast-ratio.mjs` — the library's own module, WCAG 2.1 relative luminance + ratio, tested under `node --test` |
| Measurement | Playwright reads *computed* styles and walks up for the nearest non-transparent background, so every number is what the browser actually paints, not a nominal token value |
| Screenshots | `docs/images/1626/section-theme-one-callout-backgrounds.png` (committed); full-page capture of all five sections is in the run-log directory |
| Full run log | `~/Documents/Claude/not_dave_new/1626-color-context-research-20260901.md` |

**On portability, plainly:** the measurement scripts are environment-specific — they need
Python Playwright, a `gyst`'d checkout with a built `component-library-twig/dist/css`, and a
local Drupal with content moderation. They are therefore **not committed to this repo**; they
live alongside the run log and can be attached to this PR or the issue on request. Two things
*are* portable and need nothing from me:

- **§3.6 reproduces the headline failure by hand in four clicks**, with no code at all. That is
  the path a reviewer should use to confirm the finding.
- **The WCAG maths is already in the repo** — `contrast-ratio.mjs`, with its own tests. Phase 3
  (§5.3 Option C) is precisely the recommendation to move this measurement *into* the repo as a
  build gate, so that it stops depending on one person's laptop. The precedent already exists:
  yalesites-project#1514 committed `section-background-contrast.mjs` with its output.

Contrast numbers are computed from the **shipped** token values (`tokens/build/`), not the
authored hex in `figma-transformed-tokens.json`. Style Dictionary's `color/hsl` transform
(`tokens/sd.config.js:81,101,124`) rounds H/S/L to integers, and **30 of 42 palette primitives
drift by 1–3 per channel** as a result (12 are identical; the largest drift is
`color.orange.coral`, `#ff6654` -> `#ff6352`) — `color.blue.yale` authors as `#00356b` and ships as
`#00366b`; `color.gray.800` authors as `#222222` and ships as `#212121`. The shipped value is
what the browser rasterises, so it is the correct basis for a contrast check. **That drift is
itself worth a ticket** — the design system's source of truth and its output disagree.
