# YaleSites MathJax

Renders **LaTeX** mathematical notation in the **WYSIWYG Text** block using
[MathJax](https://www.mathjax.org/). Built on the contributed
[`mathjax`](https://www.drupal.org/project/mathjax) module, this module adds a
text filter that attaches the MathJax library **only on pages that actually
contain math**, so pages without math carry no extra page weight. MathJax emits
accessible MathML for assistive technology.

> Note: MathML _source_ input (`<math>…</math>` markup typed by an editor) is
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
