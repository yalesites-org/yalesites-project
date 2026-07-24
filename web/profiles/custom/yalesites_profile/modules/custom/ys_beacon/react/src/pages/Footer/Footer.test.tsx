import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { render } from "@testing-library/react";
import Footer from "./Footer";

// Footer reads its text from the runtime widget element's data-footer at render
// time, so set the attribute then render.
const renderFooter = (footer: string) => {
  document
    .getElementById("ys-beacon-chat-widget")
    ?.setAttribute("data-footer", footer);
  return render(<Footer />);
};

beforeEach(() => {
  const widget = document.createElement("div");
  widget.id = "ys-beacon-chat-widget";
  document.body.appendChild(widget);
});

afterEach(() => {
  document.getElementById("ys-beacon-chat-widget")?.remove();
});

describe("Footer sanitization", () => {
  it("renders an anchor from HTML in the footer text instead of escaping it", () => {
    const { container } = renderFooter('Visit <a href="https://example.com">Yale</a>.');

    const link = container.querySelector("a");
    expect(link).not.toBeNull();
    expect(link).toHaveAttribute("href", "https://example.com");
    expect(link).toHaveTextContent("Yale");
  });

  it("strips disallowed markup such as script from the footer", () => {
    const { container } = renderFooter(
      'Safe <a href="https://example.com">link</a><script>alert(1)</script>'
    );

    expect(container.querySelector("a")).not.toBeNull();
    expect(container.querySelector("script")).toBeNull();
    expect(container.innerHTML).not.toContain("alert(1)");
  });

  it("wraps the pipe separator in a styled span injected after sanitization", () => {
    const { container } = renderFooter("A | B");

    const span = container.querySelector("span");
    expect(span).not.toBeNull();
    expect(span?.textContent).toBe("|");
    // The span is injected AFTER DOMPurify runs; its inline style (not in the
    // allowlist) survives only because of that ordering. Asserting the style
    // fails if the replacement is moved before sanitization.
    expect(span).toHaveAttribute("style");
  });
});
