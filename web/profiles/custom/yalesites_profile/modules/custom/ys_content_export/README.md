# YaleSites Content Export

Adds an **Export to CSV** action to each Manage Content admin page (Manage
Pages, Posts, Events, Profiles, Resources). The export downloads a spreadsheet
of that content type's items so site admins can review or share a content list.

## For admins

On any Manage Content page (e.g. `admin/content/manage-pages`) use the **Export
to CSV** button. The file opens in Excel, Numbers, or Google Sheets and
includes one row per item with these columns:

- **Title**, **URL** (path alias), **Published** (Yes/No), and
  **CAS Protected** (Yes/No — whether the item requires CAS login)
- **Tags**, **Audience**, **Custom Vocab** (on every type)
- The type's category column — **Category** (Pages, Posts), **Event Category**,
  **Resource Category** — or **Affiliation** (Profiles)

Taxonomy cells list every applied term, separated by ", " (matching the on-screen
columns). The export reflects the same items you see in the Manage view — including
any filters or search you have applied — and both published and unpublished items,
subject to your access.

## For developers

- `ContentExportBuilder` — pure column map + row builder; `sanitizeCell()`
  neutralises CSV formula injection (values starting with `=`, `+`, `-`, `@`,
  tab or carriage return are prefixed with a quote). Unit tested.
- `Controller\ContentExportController::export($bundle, $request)` — resolves the
  matching Manage view's filtered node ids (replaying the request's exposed-filter
  query) and streams the CSV in chunks, gated by the `yalesites manage settings`
  permission (same as the Manage views).
- `Plugin\Menu\LocalAction\ContentExportLocalAction` — forwards the Manage page's
  active filter query onto the export link so the button exports what you see.
- One route + one menu local action per content type, so the button appears on
  each Manage view page.

### Scope notes

- CSV only (opens in Excel); a native `.xlsx` format was not added in v1.
- Considered the `views_data_export` contrib module; a single custom exporter
  was chosen to avoid five duplicated export-display configs and a new
  serialization surface. See the PR for the trade-off.
- The user-guide/KB page update called for in the issue is tracked separately.
