import { describe, it, expect } from "vitest";
import { parseAnswer } from "./AnswerParser";
import { Citation } from "../../api";

// Minimal citation with a distinguishing url; parseAnswer only reads url/id.
const citation = (url: string): Citation => ({
  content: "content",
  id: "search-id",
  title: null,
  filepath: null,
  url,
  metadata: null,
  chunk_id: null,
  reindex_id: null,
});

describe("parseAnswer", () => {
  it("rewrites [docN] markers to ^n^ superscripts and renumbers from 1", () => {
    const result = parseAnswer({
      answer: "Alpha [doc2] beta [doc1].",
      citations: [citation("a"), citation("b")],
    });

    // Markers are renumbered in the order encountered, starting at 1.
    expect(result.markdownFormatText).toBe("Alpha  ^1^  beta  ^2^ .");
    expect(result.citations.map((c) => c.reindex_id)).toEqual(["1", "2"]);
    // The original doc index is retained on id for de-duplication.
    expect(result.citations.map((c) => c.id)).toEqual(["2", "1"]);
  });

  it("de-duplicates a repeated marker into one citation and one number", () => {
    const result = parseAnswer({
      answer: "See [doc1] and again [doc1].",
      citations: [citation("a")],
    });

    expect(result.citations).toHaveLength(1);
    expect(result.markdownFormatText).toBe("See  ^1^  and again  ^1^ .");
  });

  it("keeps distinct doc indices separate even when their URLs match (dedup is by index, not URL)", () => {
    // Documents the divergence from the server-side CitationFormatter, which
    // de-duplicates by URL; the widget de-duplicates by original doc index.
    const result = parseAnswer({
      answer: "[doc1][doc2]",
      citations: [citation("same"), citation("same")],
    });

    expect(result.citations).toHaveLength(2);
    expect(result.markdownFormatText).toBe(" ^1^  ^2^ ");
  });

  it("leaves an out-of-range marker untouched and adds no citation", () => {
    const result = parseAnswer({
      answer: "Missing [doc9] source.",
      citations: [citation("a")],
    });

    expect(result.citations).toHaveLength(0);
    expect(result.markdownFormatText).toBe("Missing [doc9] source.");
  });

  it("returns the text unchanged with no citations when there are no markers", () => {
    const result = parseAnswer({
      answer: "A plain answer with no citations.",
      citations: [citation("a")],
    });

    expect(result.citations).toHaveLength(0);
    expect(result.markdownFormatText).toBe("A plain answer with no citations.");
  });
});
