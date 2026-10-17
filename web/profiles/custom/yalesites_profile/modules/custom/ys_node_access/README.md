# YaleSites Node Access

## Description
The YaleSites Node Access module offers a flexible mechanism that allows site authors to control access to the canonical view of a node exclusively for CAS authenticated users. This feature serves as a valuable tool for selectively restricting access to specific page content. However, it is important to note that this module is not intended to function as a secure repository for sensitive content. Other mechanisms within the platform may still expose assets and metadata associated with these nodes to users who are not authenticated, which means that sensitive content should be handled through alternative, more secure means.

## Bulk CAS protection

The Manage Pages, Posts, Events, Profiles and Resources views offer two bulk actions in their **Action** dropdown: **CAS protect** and **Remove CAS protection**.

- **CAS protect** never saves on its own. The action records the selection in the private tempstore and the bulk form redirects to `/admin/content/cas-protection/protect`. That page shows the same warning as the node form's modal, then lists the selected items. Nothing changes until the editor confirms, and **Cancel** leaves every item as it was.
- **Remove CAS protection** has no warning, matching the node form, which only warns when enabling. Its route (`/admin/content/cas-protection/remove`) starts the batch straight away. That route has no CSRF token, on purpose; `CasUnprotectController` explains why.

Both run as a batch (`CasProtectionBatch`), ten nodes per pass, so a large selection doesn't hit a request's time limit. Each node is loaded and access-checked again when its turn comes. The finish message reports how many items changed, and counts separately the ones that were skipped because they already had the value, that the editor could no longer change, that had been deleted, or that failed to save.

`CasProtectionUpdater` holds the rules for one node:

- **Access.** The editor needs edit access to the node and the field. On published moderated content they also need the transition that keeps it published. A Contributor can edit published content but only into a draft, and must not be able to change who sees a live page.
- **Revisions.** The default revision is saved as a new revision in its current moderation state. Saving it rewrites the node's access grants, so a protected page is hidden from anonymous visitors right away. If a draft is in progress on top of it, the draft is re-saved with the same value too. That keeps the draft as the latest revision, and stops it from undoing the change when it is published.

The warning copy is defined once, in `CasProtectionWarning`. The node form's modal (`js/cas_protection_modal.js`) reads it from `drupalSettings`, so change the wording in that class, not in the JavaScript.

## Running tests

This module has PHPUnit tests under `tests/src/` (`Unit/`, `Kernel/`, and `Functional/`). Run them from the project root on the local Lando environment, passing the module's `tests` path so PHPUnit only discovers this module's tests (not Drupal core/contrib):

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_node_access/tests"
```

Add `--testdox` for readable output. Unit-only tests (no database) can also be run with the shorthand `lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_node_access/tests`.
