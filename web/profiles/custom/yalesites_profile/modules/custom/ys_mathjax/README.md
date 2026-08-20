# YaleSites MathJax

Renders **LaTeX** mathematical notation in the **WYSIWYG Text** block using
[MathJax](https://www.mathjax.org/). Built on the contributed
[`mathjax`](https://www.drupal.org/project/mathjax) module, this module adds a
text filter that attaches the MathJax library **only on pages that actually
contain math**, so pages without math carry no extra page weight. MathJax emits
accessible MathML for assistive technology.

> Note: MathML *source* input (`<math>…</math>` markup typed by an editor) is
> not supported in v1 — the Basic HTML format's `filter_html` strips those
> elements before rendering. Enabling MathML source would require adding the
> MathML elements to Basic HTML's allowed HTML (tracked as a follow-up). Editors
> author math as LaTeX.

## For editors: how to add math notation

Type math in a **Text** block using these delimiters:

- **Inline math** (in a line of text): wrap it in `\(` and `\)`
  Example: `The mass–energy relation is \(E = mc^2\).`
- **Display math** (its own centered line): wrap it in `$$ ... $$` or `\[ ... \]`
  Example: `$$ \int_0^1 x^2 \, dx = \tfrac{1}{3} $$`

The math is written in standard LaTeX and rendered as accessible output when the
page loads.

### Notes

- A single dollar sign (`$`) does **not** start math, so ordinary text such as
  "Tickets are $5" is unaffected.
- Punctuation inside the delimiters is left exactly as typed. Multi-row
  constructs work as they do in standard LaTeX — `\\` ends a row and `&`
  separates columns:

  ```
  $$\begin{bmatrix} a & b \\ c & d \end{bmatrix}$$
  ```

## For developers

- `MathDelimiterDetector::hasMath()` — the pure delimiter/MathML check used to
  decide whether to load the library (unit tested).
- `Plugin\Filter\YsMathjaxFilter` — extends the contrib `MathjaxFilter`;
  attaches the library via the parent only when `hasMath()` is TRUE.
- `Plugin\Filter\YsTypogrifyFilter` — extends the contrib `TypogrifyFilter` and
  masks math regions so typographic replacement cannot rewrite LaTeX before
  MathJax sees it. `ys_mathjax_filter_info_alter()` swaps it in for the
  `typogrify` plugin, so the protection applies to every text format that runs
  typogrify (`basic_html`, `heading_html`, `restricted_html`), not just the ones
  with the MathJax filter enabled.
- Delimiters and MathJax options are configured in `mathjax.settings`
  (`config_type: 0`, single-dollar inline math disabled, and `use_cdn: 0` so the
  library is self-hosted rather than loaded from a CDN).
- The library itself comes from the `mathjax` package repository in the **root**
  `composer.json` and is installed by `composer install` into
  `web/libraries/MathJax` — capital M, capital J, which the contrib module
  hardcodes. If a checkout has no `web/libraries/MathJax`, math silently stops
  rendering and the status report shows the contrib module's "local library
  files could not be found" error; run `composer install` rather than
  re-enabling the CDN.
- The install is **pruned after composer places it**. The upstream package is
  66 MB across 3,147 files, and CI force-adds the gitignored `web/libraries`
  into the Pantheon artifact on every deploy, so all of it would be committed
  even though a math-heavy page only ever requests about 448 KB.
  `ScriptHandler::pruneMathJaxLibrary()` (registered on the root
  `post-install-cmd` and `post-update-cmd`) removes `unpacked/` — an unminified
  mirror of the packed tree that `MathJax.js` never loads — plus `test/`, the
  upstream sample suite, and `docs/` on the builds that ship one (the pinned
  2.7.9 npm dist has no `docs/`; the upstream git tree at that tag does). A
  fresh `composer install` should therefore leave **about 44 MB across 1,863
  files**, with no `unpacked/`, `test/` or `docs/` directory. The prune is a
  no-op when those are already gone, and only warns rather than failing the
  build if a removal cannot be completed, since the only thing at stake is
  artifact size. `MathjaxLibraryInstallTest` asserts
  both halves — that the directories are gone and that the runtime tree
  survives — but note it does not run in CI (`composer unit-test` is a stub),
  so it is a local guard rather than a gate. One consequence worth knowing:
  `MathJax.js` tells developers to load `unpacked/MathJax.js` when debugging a
  rendering problem, and the prune removes it. To get it back temporarily use
  `composer reinstall mathjax/mathjax --no-scripts`, which re-extracts the
  package without re-running the prune. A plain `composer install` will not
  restore it: composer decides what to install from the lock and
  `vendor/composer/installed.php`, so with the package still recorded as
  installed it reports "Nothing to install, update or remove" and leaves the
  pruned tree alone.
- **The pin must stay on MathJax 2.7.1 or later.** Contrib `mathjax` 4.x still
  targets the MathJax 2.x API, so this is deliberately not a 3.x/4.x library.
  Within 2.x, 2.7.0 and earlier fetch the accessibility menu from `[Contrib]`,
  a path hardcoded to `cdn.mathjax.org`; 2.7.1+ bundle the a11y extensions and
  reference them locally, which is what removes the last third-party origin.
  `tests/src/Unit/MathjaxLibraryInstallTest.php` guards both the install path
  and that property.
- `cdn_url` is deliberately left at the contrib default. It is dead while
  `use_cdn: 0`, the config schema wants a value, and `drush deploy` re-imports
  `use_cdn: 0` anyway — so there is nothing to gain from editing it. Be aware
  that it still names 2.7.0, the one build this module must not use, so toggling
  the CDN back on for debugging is not a like-for-like comparison.
