// Shared DOMPurify allowlist for the chat chrome rich text - the disclaimer and
// footer, which intentionally support links and basic inline formatting.
// Keeping it in one place stops the two from silently diverging. It is
// deliberately narrower than XSSAllowTags, which governs full citation content.
export const RichTextAllowTags = ["a", "b", "i", "em", "strong", "br", "span"];
export const RichTextAllowAttr = ["href", "target", "rel", "class"];
