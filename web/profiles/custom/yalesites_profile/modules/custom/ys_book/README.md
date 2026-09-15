# YaleSites Book

Customizations to Drupal's Book module for YaleSites. Overrides the
`custom_book_block` `book.manager` service with `YsExpandBookManager` so that
CAS-protected (and other access-restricted) published pages remain visible in the
book navigation — flagged for a lock icon — rather than being filtered out, and
invalidates collection-navigation caches on book node changes.

## Who can manage content collections

Collection management is gated on contrib book's narrow **`reorder book pages`**
permission, via the `_ys_book_outline_access` access check
(`src/Access/BookOutlineAccessCheck.php`). The broad **`administer book
outlines`** permission is also accepted, so a site that granted it to a role of
its own keeps working, but no platform role holds it any more.

That check gates the three screens this module owns:

| Screen | Route |
| --- | --- |
| Manage Content Collections overview | `book.admin` |
| Re-order collection pages and titles | `book.admin_edit` |
| Delete collection | `ys_book.collection_delete` |

It also gates contrib's "Child order" node tab (`book.node_child_ordering`),
which `ys_book.routing.yml` overrides for exactly that reason. Contrib's own
access check on that route additionally requires `reorder book pages`, so the
broad permission alone has never been enough there.

Everything else book-related runs off other permissions and is unaffected: the
Content Collection widget on the node form, the Outline tab and
Remove-from-outline (`add content to books`), creating a new collection
(`create new books`), and the printer-friendly export
(`access printer-friendly version`).

One cosmetic exception: contrib's `BookOutlineConstraintValidator` uses the broad
permission only to decide whether its "you can only change the book outline for
the published version" violation message includes a link to the collections page.
These roles now see the link-less variant of that message.

`administer book outlines` is contrib book's administer-everything grant, and
`editor`, `site_admin` and `platform_admin` held it only because these screens
asked for it. Deleting a collection stays gated at the same level as reordering
one, which is the access those roles already had; raising that bar would be a
separate product decision.

See `BookOutlineAccessCheck` and yalesites-org/YaleSites-Internal#1573.

## Running tests

This module has PHPUnit tests under `tests/src/`. Run them from the project root on the local Lando environment, passing the module's `tests` path so PHPUnit only discovers this module's tests (not Drupal core/contrib):

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_book/tests"
```

Add `--testdox` for readable output.
