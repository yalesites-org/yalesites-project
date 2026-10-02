# YaleSites Content Export

Adds an **Export to CSV** action to each Manage Content admin page (Manage
Pages, Posts, Events, Profiles, Resources) and to the **People** page. The
export downloads a spreadsheet of whatever list the admin is looking at, so
they can review, audit, or share it offline.

## For admins: Manage Content

On any Manage Content page (e.g. `admin/content/manage-pages`) use the **Export
to CSV** button. The file opens in Excel, Numbers, or Google Sheets and
includes one row per item with these columns:

- **Title**, **URL** (path alias), **Published** (Yes/No), and
  **CAS Protected** (Yes/No — whether the item requires CAS login)
- The type's date column, immediately after Title — **Dates** (Events),
  **Resource Publication Date** (Resources). Other types have no date column.
- **Tags**, **Audience**, **Custom Vocab** (on every type)
- The type's category column — **Category** (Pages, Posts), **Event Category**,
  **Resource Category** — or **Affiliation** (Profiles)

Taxonomy cells list every applied term, separated by ", " (matching the on-screen
columns).

The Events **Dates** cell lists _every_ occurrence of the event, oldest first,
separated by ", " — so a recurring series shows all of its dates, where the
on-screen Date column shows only the first and last. Dates render in the site's
timezone, and an all-day occurrence reads "(All day)" rather than a 12:00 am to
11:59 pm range. **Resource Publication Date** is the same `YYYY-MM-DD` shown on
the Manage Resources screen. An item with no date exports an empty cell.

Note that a cell holding many dates cannot be sorted as a date in a spreadsheet;
that trade-off was accepted in favour of showing every occurrence.

The export reflects the same items you see in the Manage view — including
any filters or search you have applied — and both published and unpublished items,
subject to your access.

## For admins: People

On the People page (`admin/people`) use the **Export to CSV** button, next to
the Add user actions. It is available to anyone who can open the page, and it
exports the accounts matching the filters and search currently applied on
screen. One row per account, with these columns:

- **Username** — the account username, matching the on-screen column. Accounts
  here are provisioned from CAS, so that username is the person's NetID. An
  account saved without one exports its display name rather than a blank cell.
- **Name** — first and last name, as the on-screen Name column shows them.
- **Status** — Active or Blocked.
- **Roles** — every role on the account, separated by ", ", matching the
  on-screen column. An account with no role beyond the implicit
  "authenticated" exports an empty cell.
- **Member for** and **Last access** — exported as `YYYY-MM-DD` dates rather
  than the relative text ("3 years 2 months", "1 week ago") shown on screen,
  because a spreadsheet can sort a date and cannot sort that text. The headers
  still match the screen so the columns can be lined up. An account that has
  never logged in exports an **empty** Last access cell, not 1970-01-01.

Dates render in the site's timezone.

## For developers

- `ContentExportBuilder` — pure column map + row builder for nodes;
  `sanitizeCell()` neutralises CSV formula injection (values starting with
  `=`, `+`, `-`, `@`, tab or carriage return are prefixed with a quote). Unit
  tested. It takes no injected services, so a `DateFormatterInterface` is
  passed into `getRow()` rather than resolved inside it; event dates reuse the
  platform's existing `event_date_only` / `event_time_only` date formats. The
  resource publication date is a date-only field already stored as `Y-m-d`, so
  it is emitted verbatim — reformatting it would only risk a timezone shift of
  a day.
- `PeopleExportBuilder` — the same shape for user accounts: a pure column map
  and row builder, service-free and unit tested, reusing
  `ContentExportBuilder::sanitizeCell()` rather than restating what makes a
  cell safe. The two timestamp columns use a `custom` `Y-m-d` format so the
  formatter does not load a date format config entity per row, and a falsy
  timestamp yields an empty cell. Roles are read off the `roles` field
  (`referencedEntities()`) rather than `UserInterface::getRoles()`, which gets
  role labels instead of machine names and keeps the implicit "authenticated"
  role out of the cell — matching what the People screen lists.
- `Controller\ExportControllerBase` — the shared half of both exports: it
  resolves an admin view's filtered entity ids (replaying the request's
  exposed-filter query, de-duplicated because a multi-value join can repeat an
  entity) and streams them to a CSV download in `CHUNK_SIZE` batches, loading
  and releasing each chunk so memory stays bounded on a long list. Subclasses
  supply only the view, entity type, headers, row callback, and filename.
- `Controller\ContentExportController::export($bundle, $request)` — the Manage
  views, gated by the `yalesites manage settings` permission (same as those
  views).
- `Controller\PeopleExportController::export($request)` — the
  `user_admin_people` view, gated by `administer users` (same as that view's
  own access check, so the button is visible to exactly the admins who can
  open the page).
- `Plugin\Menu\LocalAction\ContentExportLocalAction` — shared by every export
  button; forwards the admin page's active filter query onto the export link so
  the button exports what you see.
- One route + one menu local action per list: five content types on their
  `view.manage_*.page_1` routes, and People on `entity.user.collection` (the
  route the People view is served from).

### Scope notes

- CSV only (opens in Excel); a native `.xlsx` format was not added. The People
  request asked for "CSV/Excel" and this is the CSV half of that.
- Considered the `views_data_export` contrib module; a single custom exporter
  was chosen to avoid five duplicated export-display configs and a new
  serialization surface. See the PR for the trade-off.
- "Member for" keeps its on-screen header but changes meaning: on screen it is
  a duration, in the CSV it is the join date. Renaming it to "Member since"
  would sort better conceptually but would stop matching the screen, which the
  issue asked for; the README and KB page carry the explanation instead.
- The user-guide/KB page lives in Yale's KB, not this repo, so extending it to
  cover People is tracked separately; this README is the in-repo reference.
