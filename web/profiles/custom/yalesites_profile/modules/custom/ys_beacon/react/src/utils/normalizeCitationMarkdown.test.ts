import { describe, expect, it } from "vitest";
import { normalizeCitationMarkdown } from "./normalizeCitationMarkdown";

// End-to-end rendering of the repaired heading is asserted in Chat.test.tsx
// ("renders a stray-indented setext heading as a heading, not a code block"),
// which goes through the app's real ReactMarkdown pipeline. These are
// string-level checks of the transform itself.
//
// Every "leaves X untouched" case below is deliberately written in its LOOSE
// form -- blank line before the indented part -- so the indented line really is
// a block start. In tight form the transform is an identity no matter how it is
// implemented, and the assertion proves nothing.
describe("normalizeCitationMarkdown", () => {
  describe("repairs a setext heading broken by stray indentation", () => {
    it("caps an indent of 4 or more columns to 3 spaces", () => {
      expect(normalizeCitationMarkdown("       Heading\n-------\n")).toBe(
        "   Heading\n-------\n"
      );
    });

    it("caps a tab indent, which is one character but four columns", () => {
      expect(normalizeCitationMarkdown("\tHeading\n-------\n")).toBe(
        "   Heading\n-------\n"
      );
    });

    it("caps a mixed space-and-tab indent by column width, not character count", () => {
      // Two characters, but the tab advances to column 4.
      expect(normalizeCitationMarkdown("  \tHeading\n-------\n")).toBe(
        "   Heading\n-------\n"
      );
    });

    it("repairs an equals-underlined heading too", () => {
      expect(normalizeCitationMarkdown("       Heading\n=======\n")).toBe(
        "   Heading\n=======\n"
      );
    });

    it("leaves an indent already under 4 columns alone", () => {
      expect(normalizeCitationMarkdown("   Heading\n-------\n")).toBe(
        "   Heading\n-------\n"
      );
      expect(normalizeCitationMarkdown("Heading\n-------\n")).toBe(
        "Heading\n-------\n"
      );
    });
  });

  describe("leaves indentation that carries meaning alone", () => {
    it("does not touch a loose list's continuation paragraph", () => {
      const loose = "10. First item text.\n\n    Second paragraph of item 10.\n";

      expect(normalizeCitationMarkdown(loose)).toBe(loose);
    });

    it("does not touch a nested bullet after a blank line", () => {
      const nested = "1.  outer item\n\n    - nested bullet\n";

      expect(normalizeCitationMarkdown(nested)).toBe(nested);
    });

    it("does not touch a deliberate indented code block", () => {
      const code = "Intro text.\n\n    const x = 1;\n    const y = 2;\n";

      expect(normalizeCitationMarkdown(code)).toBe(code);
    });

    it("does not touch an indented line that has no setext underline under it", () => {
      const indented = "Intro.\n\n       just deeply indented prose\n";

      expect(normalizeCitationMarkdown(indented)).toBe(indented);
    });
  });

  describe("respects fenced code blocks", () => {
    it("does not touch content inside a fence, even after a blank line", () => {
      const fenced = "```\n    first\n\n        second\n-------\n```\n";

      expect(normalizeCitationMarkdown(fenced)).toBe(fenced);
    });

    it("does not treat a shorter inner run as closing a longer fence", () => {
      const fenced = "````\ncode\n```\n\n        deep\n-------\n````\n";

      expect(normalizeCitationMarkdown(fenced)).toBe(fenced);
    });

    it("does not treat a different marker as closing a fence", () => {
      const fenced = "~~~\ncode\n```\n\n        deep\n-------\n~~~\n";

      expect(normalizeCitationMarkdown(fenced)).toBe(fenced);
    });

    it("does not turn an over-indented fence run into a live fence opener", () => {
      // The fence run is indented 7. Capping it would make it a real opener
      // with no matching closer, swallowing the rest of the body into one
      // unterminated code block. The run is recognised as a fence despite its
      // indent, so it and its contents are left exactly as they are.
      const input =
        "Intro.\n\n       ```\n       code   line\n       ```\n\n       Heading\n-------\n\nTail paragraph.\n";

      const normalized = normalizeCitationMarkdown(input);

      expect(normalized).toContain("       ```\n       code   line\n       ```");
      // Nothing after the fence is swallowed.
      expect(normalized).toContain("Tail paragraph.");
      // The heading below the closed fence is still repaired.
      expect(normalized).toContain("   Heading\n-------");
    });

    // The shape where indent-agnostic fence detection is load-bearing: an
    // over-indented fence run sitting directly above a setext underline. If
    // the run were not recognised as a fence, the setext rule would cap it
    // into a live opener with no closer, swallowing the rest of the body.
    it("does not cap an over-indented fence run above a setext underline", () => {
      const input = "Intro.\n\n       ```\n-------\n\nTail paragraph.\n";

      expect(normalizeCitationMarkdown(input)).toBe(input);
    });

    it("keeps a fence with an info string intact", () => {
      const fenced = "```js\n    const a = 1;\n\n        const b = 2;\n-------\n```\n";

      expect(normalizeCitationMarkdown(fenced)).toBe(fenced);
    });

    it("fails safe on an unclosed fence by changing nothing after it", () => {
      const unclosed = "```\ncode\n\n       Heading\n-------\n";

      expect(normalizeCitationMarkdown(unclosed)).toBe(unclosed);
    });
  });

  it("repairs the reported citation content", () => {
    const content =
      "[ Spotlight - Landscape ](/blocks-for-visreg/spotlight-landscape)\n" +
      "-----------------------------------------------------------------\n" +
      "\n" +
      "       Spotlight - Landscape Heading\n" +
      "------------------------------\n" +
      "\n" +
      " Spotlight - Landscape Subheading\n";

    expect(normalizeCitationMarkdown(content)).toContain(
      "   Spotlight - Landscape Heading\n"
    );
    expect(normalizeCitationMarkdown(content)).toContain(
      " Spotlight - Landscape Subheading\n"
    );
  });
});
