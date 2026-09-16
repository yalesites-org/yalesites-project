import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { render } from "@testing-library/react";
import Disclaimer from "./Disclaimer";

// Disclaimer reads its text from the runtime widget element's data-disclaimer.
beforeEach(() => {
  const widget = document.createElement("div");
  widget.id = "ys-beacon-chat-widget";
  widget.setAttribute("data-disclaimer", "AI generated content may be incorrect.");
  document.body.appendChild(widget);
});

afterEach(() => {
  document.getElementById("ys-beacon-chat-widget")?.remove();
});

describe("Disclaimer", () => {
  it("applies the given id to the paragraph so the input can reference it via aria-describedby", () => {
    const { container } = render(<Disclaimer id="beacon-disclaimer" />);

    const paragraph = container.querySelector("p");
    expect(paragraph).not.toBeNull();
    expect(paragraph).toHaveAttribute("id", "beacon-disclaimer");
  });
});

describe("Disclaimer link rendering (#1457)", () => {
  it("renders an anchor from HTML in the disclaimer text instead of escaping it", () => {
    document
      .getElementById("ys-beacon-chat-widget")
      ?.setAttribute(
        "data-disclaimer",
        'For support, please <a href="mailto:help@example.com">contact us</a>.'
      );

    const { container } = render(<Disclaimer id="beacon-disclaimer" />);

    const link = container.querySelector("a");
    expect(link).not.toBeNull();
    expect(link).toHaveAttribute("href", "mailto:help@example.com");
    expect(link).toHaveTextContent("contact us");
  });

  it("strips disallowed markup such as script from the disclaimer", () => {
    document
      .getElementById("ys-beacon-chat-widget")
      ?.setAttribute(
        "data-disclaimer",
        'Safe <a href="https://example.com">link</a><script>alert(1)</script>'
      );

    const { container } = render(<Disclaimer id="beacon-disclaimer" />);

    expect(container.querySelector("a")).not.toBeNull();
    expect(container.querySelector("script")).toBeNull();
    expect(container.innerHTML).not.toContain("alert(1)");
  });
});
