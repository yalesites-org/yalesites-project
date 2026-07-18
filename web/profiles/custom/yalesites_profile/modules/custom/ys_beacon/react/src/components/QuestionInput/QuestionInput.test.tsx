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
});
