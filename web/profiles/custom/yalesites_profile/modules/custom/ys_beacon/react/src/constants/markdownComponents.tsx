import type { Components, Options } from "react-markdown";

// Headings inside model- or editor-authored markdown (the chat answer and the
// citation source body) must never introduce a page-level <h1>, and must nest
// under the dialog's own title (an <h2>). Demote every injected heading two
// levels so the highest becomes an <h3>, keeping a single, valid page heading
// hierarchy while the modal is open (WCAG 1.3.1 / 2.4.6). Applied via
// react-markdown's `components`, which also remaps raw HTML headings that
// rehype-raw passes through.
export const demotedHeadingComponents: Components = {
  h1: "h3",
  h2: "h4",
  h3: "h5",
  h4: "h6",
  h5: "h6",
  h6: "h6",
};

const HEADING = /^h[1-6]$/;

// A citation's source content can contain a heading whose entire text is a
// markdown link -- the indexed page's own title, wrapped in an <a> by the view
// mode that gets rendered for the index. react-markdown decorates every <a>
// regardless of ancestry, so left alone that renders a second, redundant link
// duplicating the citation's own title link shown just above it, often with a
// root-relative href that is broken outside the source page.
//
// Pair this with react-markdown's `unwrapDisallowed`, which replaces a
// rejected element with its own children: the <a> is dropped and its text
// kept, so the heading renders as plain, non-link text. Filtering runs on the
// hast tree, so it also catches links inside raw HTML headings that
// rehype-raw passes through.
//
// Only a heading whose *entire* text is the link is unwrapped. A heading that
// mixes prose with a genuine link ("See the [policy page](...) for details")
// keeps it -- dropping that link would discard a destination the reader has no
// other way to reach.
export const allowLinkOutsideHeading: NonNullable<Options["allowElement"]> = (
  element,
  _index,
  parent
) => {
  if (element.tagName !== "a" || !("tagName" in parent)) {
    return true;
  }
  if (!HEADING.test(parent.tagName)) {
    return true;
  }

  const meaningfulChildren = parent.children.filter(
    (child) => child.type !== "text" || child.value.trim() !== ""
  );

  return meaningfulChildren.length !== 1;
};
