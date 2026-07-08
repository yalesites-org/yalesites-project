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
- Because the Text block also applies typographic replacement (Typogrify),
  prefer LaTeX macros over literal punctuation inside math — e.g. use `\prime`
  and `\ldots` rather than `'` and `...` so the source is not altered before
  MathJax renders it.

## For developers

- `MathDelimiterDetector::hasMath()` — the pure delimiter/MathML check used to
  decide whether to load the library (unit tested).
- `Plugin\Filter\YsMathjaxFilter` — extends the contrib `MathjaxFilter`;
  attaches the library via the parent only when `hasMath()` is TRUE.
- Delimiters and MathJax options are configured in `mathjax.settings`
  (`config_type: 0`, single-dollar inline math disabled).
