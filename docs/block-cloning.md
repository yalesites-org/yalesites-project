# Cloning Blocks in Layout Builder

## Overview

Some pages repeat the same block over and over — a checkerboard of Content
Spotlights, a row of Facts, a stack of Callouts. Instead of adding a new block
and re-entering every setting each time, you can **clone** an existing block and
then edit the copy.

Cloning is available in **Edit Layout and Content** (Layout Builder) on any
content type that uses it.

## How to clone a block

1. Open the page and choose **Edit Layout and Content**.
2. Hover over (or keyboard-focus) the block you want to copy and open its
   pencil/contextual menu.
3. Choose **Clone block**.
4. The copy appears immediately below the original, in the same region, with all
   of its content and settings already filled in.
5. Edit the copy as usual (**Configure**), then press **Save layout** to publish
   your changes.

The clone is not saved until you save the layout. **Discard changes** removes the
copy again, exactly like any other unsaved edit.

## What gets copied

- Every field of the block — text, links, media references, and repeatable
  items such as gallery images, cards, tabs, accordion items or facts.
- Block settings such as the heading, style/variation options and padding.
- The block's position: the copy is always placed directly after the original.

The copy is a **separate block**. Editing the clone never changes the original,
and editing the original never changes the clone.

## Limitations

- **Reusable blocks cannot be cloned.** A reusable block is already shared
  across pages by design, so a copy would silently disconnect from the
  original.
- **Blocks with no content of their own cannot be cloned** — views listings,
  page metadata, profile contact details and other built-in blocks. For all of
  these, **Clone block** simply does not appear in the menu.
- **Locked regions.** If a section or region has been locked so blocks cannot
  be added to it, cloning is unavailable there too.
- Cloning copies a block **within the same page**. To reuse a block on another
  page, either use a reusable block from the block library or clone the whole
  page.

## Accessibility

**Clone block** is a normal contextual-menu link, so it is reachable with the
keyboard in the same way as **Configure**, **Move** and **Remove block**. After
cloning, screen readers hear "Block cloned. The copy was added below the
original."

## Notes for the YaleSites team

The editor-facing Help Center lives outside this repository. This document is
the source material for that separate, external article — publishing it there is
a follow-up task for the documentation team.

Cloning is implemented in `ys_layouts` (`BlockCloner`, `CloneBlockController`,
`CloneContextualLinks`, `CloneBlockAccessCheck`). One half of the feature is in
the theme: `atomic/templates/paragraphs/_gallery-item.twig` renders the Gallery
modal from the paragraph object (twig_tweak's `view` filter) instead of by
paragraph ID, because a cloned paragraph has no ID until the layout is saved and
rendering by ID produced an empty lightbox in the Layout Builder preview. That
change ships on an `atomic` branch with the same name as the
`yalesites-project` branch so the multidev build picks it up.
