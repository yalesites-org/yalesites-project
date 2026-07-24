import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { render } from "@testing-library/react";
import Footer from "./Footer";

// Footer reads its text from the runtime widget element's data-footer.
beforeEach(() => {
  const widget = document.createElement("div");
  widget.id = "ys-beacon-chat-widget";
  widget.setAttribute("data-footer", "Yale University");
  document.body.appendChild(widget);
});

afterEach(() => {
  document.getElementById("ys-beacon-chat-widget")?.remove();
});

describe("Footer sanitization", () => {
  it("renders an anchor from HTML in the footer text instead of escaping it", () => {
    document
      .getElementById("ys-beacon-chat-widget")
      ?.setAttribute("data-footer", 'Visit <a href="https://example.com">Yale</a>.');

    const { container } = render(<Footer />);

    const link = container.querySelector("a");
    expect(link).not.toBeNull();
    expect(link).toHaveAttribute("href", "https://example.com");
    expect(link).toHaveTextContent("Yale");
  });

  it("strips disallowed markup such as script from the footer", () => {
    document
      .getElementById("ys-beacon-chat-widget")
      ?.setAttribute(
        "data-footer",
        'Safe <a href="https://example.com">link</a><script>alert(1)</script>'
      );

    const { container } = render(<Footer />);

    expect(container.querySelector("a")).not.toBeNull();
    expect(container.querySelector("script")).toBeNull();
    expect(container.innerHTML).not.toContain("alert(1)");
  });

  it("wraps the pipe separator in a span injected after sanitization", () => {
    document
      .getElementById("ys-beacon-chat-widget")
      ?.setAttribute("data-footer", "A | B");

    const { container } = render(<Footer />);

    const span = container.querySelector("span");
    expect(span).not.toBeNull();
    expect(span?.textContent).toBe("|");
  });
});
