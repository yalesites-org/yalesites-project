import { describe, it, expect } from "vitest";
import DOMPurify from "dompurify";
import { XSSAllowTags } from "./xssAllowTags";

// The chat citation panel renders retrieved content with
// DOMPurify.sanitize(content, { ALLOWED_TAGS: XSSAllowTags }). These tests pin
// the security contract of that allowlist so a regression that widened it
// (e.g. adding iframe) or neutered the sanitizer would fail.
describe("XSSAllowTags citation allowlist", () => {
  it("excludes tags that load, frame, or script external content", () => {
    const dangerous = [
      "script",
      "iframe",
      "object",
      "embed",
      "svg",
      "style",
      "form",
      "video",
      "audio",
      "base",
      "meta",
      "link",
    ];
    for (const tag of dangerous) {
      expect(XSSAllowTags).not.toContain(tag);
    }
  });

  it("strips script and iframe while keeping allowed markup", () => {
    const dirty =
      '<p>Safe <a href="https://example.com">link</a></p>' +
      "<script>alert(1)</script>" +
      '<iframe src="https://evil.example"></iframe>';
    const clean = DOMPurify.sanitize(dirty, { ALLOWED_TAGS: XSSAllowTags });

    expect(clean).toContain("<p>");
    expect(clean).toContain("<a");
    expect(clean).not.toContain("<script");
    expect(clean).not.toContain("alert(1)");
    expect(clean).not.toContain("<iframe");
  });

  it("strips event-handler attributes", () => {
    const clean = DOMPurify.sanitize('<a href="#" onclick="alert(1)">x</a>', {
      ALLOWED_TAGS: XSSAllowTags,
    });
    expect(clean).not.toContain("onclick");
  });
});
