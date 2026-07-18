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
