// Tags permitted when rendering retrieved citation content. Embedded-content
// tags that load or frame external resources (iframe, embed, object, video,
// audio, svg) are deliberately excluded: citation content is the rendered
// HTML of editor-authored Drupal entities, and an editor able to embed raw
// HTML must not be able to frame arbitrary third-party content inside the
// chat widget. DOMPurify strips event-handler attributes regardless.
//
// Table tags (table, tr, td, th, thead, tbody, tfoot) are intentionally
// omitted: Beacon does not allow tables (the system-instructions authoring
// format forbids them), so the chat renderer is kept in agreement.
export const XSSAllowTags = ['a', 'img', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p', 'span', 'small', 'del', 'picture', 'i', 'u', 'sup', 'sub', 'strong', 'strike', 'code', 'pre', 'section', 'article', 'ul', 'ol', 'li', 'br', 'em', 'blockquote'];

// Attributes permitted on those tags. Without an explicit allowlist DOMPurify
// falls back to its defaults, which include `style` -- and inline CSS carried
// over from a rendered Drupal page has no business styling the chat widget.
// Citation content only needs enough to keep links and images meaningful;
// react-markdown supplies `target`/`rel` on the links it renders itself.
export const XSSAllowAttr = ['href', 'src', 'srcset', 'alt', 'title', 'target', 'rel'];
