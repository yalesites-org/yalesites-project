import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QuestionInput } from "./QuestionInput";

describe("QuestionInput accessibility", () => {
  it("labels the input with an associated <label> element, not just an aria-label", () => {
    render(<QuestionInput onSend={vi.fn()} disabled={false} />);

    const input = screen.getByRole("textbox") as HTMLTextAreaElement;
    // The accessible name must come from a real, associated <label>, so the
    // redundant aria-label is gone and a label[for] targets the input.
    expect(input.getAttribute("aria-label")).toBeNull();
    expect(input.id).toBeTruthy();
    const label = document.querySelector(`label[for="${input.id}"]`);
    expect(label).not.toBeNull();
    expect(label).toHaveTextContent("Ask a question");
    expect(screen.getByRole("textbox", { name: "Ask a question" })).toBe(input);
  });

  it("forwards describedById to the input's aria-describedby", () => {
    render(
      <QuestionInput
        onSend={vi.fn()}
        disabled={false}
        describedById="beacon-disclaimer"
      />
    );

    expect(screen.getByRole("textbox")).toHaveAttribute(
      "aria-describedby",
      "beacon-disclaimer"
    );
  });

  it("names the send button 'Ask question' without the redundant word 'button'", () => {
    render(<QuestionInput onSend={vi.fn()} disabled={false} />);

    expect(
      screen.getByRole("button", { name: "Ask question" })
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /button/i })
    ).not.toBeInTheDocument();
  });

  it("shows the label as a persistent visible label, not an off-screen clipped one (WCAG 3.3.2)", () => {
    render(<QuestionInput onSend={vi.fn()} disabled={false} />);

    const label = screen.getByText("Ask a question");
    expect(label.tagName).toBe("LABEL");
    // The placeholder disappears on typing, so the label must stay on screen;
    // it must no longer use the visually-hidden off-screen clip.
    expect(label.className).not.toContain("visuallyHidden");
  });

  it("keeps the send button icon decorative so the button name is not duplicated or contradicted", () => {
    const { container } = render(
      <QuestionInput onSend={vi.fn()} disabled={false} />
    );

    const svg = container.querySelector("button svg");
    expect(svg).not.toBeNull();
    expect(svg?.getAttribute("aria-hidden")).toBe("true");
    // The old <title>Ask any question</title> contradicted the "Ask question"
    // accessible name; a decorative icon carries no title.
    expect(svg?.querySelector("title")).toBeNull();
  });
});
