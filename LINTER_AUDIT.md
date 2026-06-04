# Linter Audit — ESLint vendor scan & custom JS debt

Status: **open / to be addressed as a separate refactor (with tests)**
Discovered: 2026-06-04, during work on issue #1274 (which added no JS — this is
pre-existing and unrelated to that ticket).

## Summary

`npm run lint` is effectively broken at the `lint:js` step:

1. **It never completes** because ESLint scans the profile's composer `vendor/`
   directory (a full copy of Drupal **core** + contrib, including large minified
   bundles), taking 20+ minutes at 100% CPU before anyone sees output.
2. **Once that is fixed, it fails** — ESLint surfaces **548 problems
   (433 errors, 115 warnings)** in pre-existing hand-written custom module JS
   that has gone unchecked precisely because the step never finished.

Both need addressing, ideally together, as a deliberate refactor with proper
verification (run the full suite, confirm behavior of the touched JS).

## Root cause of the slowness

- `package.json` `lint:js`:
  `eslint ... {web/modules/custom,web/themes/custom,web/profiles/custom}/**/*.js`
- This repo installs contrib **into the profile**, so
  `web/profiles/custom/yalesites_profile/vendor/` contains Drupal core + contrib.
- There is **no `.eslintignore`**, and the shared config
  `@yalesites-org/eslint-config-and-other-formatting` (v1.20.0) defines **no
  `ignorePatterns`**. ESLint 8 only auto-ignores `node_modules`, not `vendor/`.
- `ignorePatterns` added to the local `.eslintrc.js` **do not work** here: the
  vendored code ships its own ESLint configs (Drupal `core/.eslintrc.json` has
  `"root": true`; many contrib modules ship `.eslintrc`), which stop the config
  cascade so the root `ignorePatterns` are never consulted for those paths.

## Proposed fix (verified working — apply as part of the refactor)

Add a root `.eslintignore`. `.eslintignore` is read once from cwd, is
authoritative, and is **not** overridden by nested configs. Use **directory-name
form** so ESLint *prunes* the directories during traversal (a contents glob like
`**/vendor/**` matches files but does not prune the dir, so the walk stays slow):

```gitignore
# Dependencies and build artifacts — not ours to lint.
node_modules
vendor
dist
build

# Compiled Vite bundle for the embedded React chat app (source is TypeScript,
# linted by the app's own tooling; only the built .js lands here).
**/react/static
```

Result: `lint:js` drops from **20+ min → ~3 s** and lints the 16 real source
files. (`web/core`, `web/modules/contrib`, `web/themes/contrib` are *not* under
the lint globs, so they need no ignore; the only reachable third-party JS is
under the profile's `vendor/` and `node_modules/`, both covered above.)

Note: `lint:styles` (stylelint over `...custom/**/*.scss`) likely has the same
vendor-scan problem and should get an equivalent `.stylelintignore` — verify and
fix in the same refactor.

## Findings — custom JS to clean up (548 problems: 433 errors, 115 warnings)

Per file (errors / warnings):

| Errors | Warnings | File |
| ---: | ---: | --- |
| 108 | 22 | `ys_embed/js/LibCal.js` |
| 87 | 14 | `ys_core/js/facts-icon-preview-chosen.js` |
| 72 | 31 | `ys_node_access/js/cas_protection_modal.js` |
| 22 | 13 | `ys_core/js/grand-hero-form.js` |
| 21 | 0 | `ys_core/js/block-form.js` |
| 19 | 4 | `ys_themes/js/component-color-picker.js` |
| 19 | 3 | `ys_book/js/book.js` |
| 17 | 3 | `ys_views_basic/assets/js/views-basic.js` |
| 13 | 0 | `ys_ai/modules/ys_ai_system_instructions/js/system-instructions.js` |
| 11 | 2 | `ys_alert/js/confirm_type_modal.js` |
| 9 | 8 | `ys_themes/js/levers.js` |
| 8 | 5 | `ys_core/js/font-preview.js` |
| 8 | 3 | `ys_core/js/gcse.js` |
| 8 | 1 | `ys_ai/modules/ys_contoso_chat/js/init.js` |
| 6 | 4 | `ys_ai/modules/ys_contoso_chat/js/events.js` |
| 5 | 2 | `ys_core/js/header-footer-settings.js` |

By rule (count):

| Count | Rule | Auto-fixable? |
| ---: | --- | --- |
| 364 | `prettier/prettier` | yes |
| 82 | `func-names` | no (review) |
| 23 | `no-var` | yes |
| 12 | `no-unused-vars` | no (review) |
| 12 | `object-shorthand` | yes |
| 12 | `max-nested-callbacks` | no (review) |
| 11 | `vars-on-top` | no (review) |
| 8 | `no-console` | no (review) |
| 5 | `no-shadow` | no (review) |
| 5 | `prefer-template` | yes |
| 5 | `no-use-before-define` | no (review) |
| 4 | `strict` | yes |
| 1 | `prefer-const` | yes |
| 1 | `valid-jsdoc` | no (review) |
| 1 | `prefer-regex-literals` | no (review) |

**409 of 548 are auto-fixable** with `eslint --fix` (formatting, `no-var`,
`object-shorthand`, `prefer-*`, `strict`). The remaining ~140 need human review
— unused vars, console statements, deep callback nesting, function naming,
variable shadowing, use-before-define.

## Suggested remediation plan

1. Land the `.eslintignore` (and a `.stylelintignore` if `lint:styles` is also
   affected) so the gate actually runs fast.
2. Run `eslint --fix` to clear the 409 mechanical issues; commit separately so the
   formatting churn is reviewable on its own.
3. Manually resolve the ~140 judgment-call issues per file, **verifying each
   touched script still behaves correctly** (these run real admin/editor UI:
   block forms, theme pickers, embeds, CAS modal, book nav, views, AI chat).
4. Re-run `npm run lint` to confirm green, and consider enforcing it in CI so the
   debt does not silently return.

## Reproduce

```bash
# After adding the .eslintignore above:
npx eslint --no-error-on-unmatched-pattern --format stylish \
  "{web/modules/custom,web/themes/custom,web/profiles/custom}/**/*.js"
```
