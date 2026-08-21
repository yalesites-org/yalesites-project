import type { Components } from "react-markdown";

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
