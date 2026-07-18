import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import Modal from "./Modal";

// The Modal portals into the widget mount element that the Drupal init.js
// creates at runtime; recreate it for each test.
beforeEach(() => {
  const widget = document.createElement("div");
  widget.id = "ys-beacon-chat-widget";
  document.body.appendChild(widget);
});

afterEach(() => {
  document.getElementById("ys-beacon-chat-widget")?.remove();
});

const renderModal = (close = vi.fn()) =>
  render(
    <Modal
      show
      close={close}
      header={<span>Header</span>}
      footer={null}
      variant=""
    >
      <p>Chat body</p>
    </Modal>
  );

describe("Modal close button", () => {
  it("renders the close button inside the modal header", () => {
    renderModal();

    const header = screen.getByRole("banner");
    const closeButton = within(header).getByRole("button", {
      name: /close modal/i,
    });

    expect(closeButton).toBeInTheDocument();
  });

  it("calls close when the close button is clicked", async () => {
    const close = vi.fn();
    renderModal(close);

    await userEvent.click(
      screen.getByRole("button", { name: /close modal/i })
    );

    expect(close).toHaveBeenCalledTimes(1);
  });
});

// Regression guard for #1397: at short viewports the header must not overlap or
// blur the content (it was position:absolute + backdrop-filter), and the body
// must stay scrollable so no content is lost (WCAG 1.4.10 Reflow, 2.4.11 Focus
// Not Obscured). jsdom does not lay out, so these assert the CSS contract the
// fix relies on rather than pixel positions.
describe("Modal short-viewport layout (#1397)", () => {
  it("keeps the header in normal flow so it never overlaps the content", () => {
    renderModal();

    const header = screen.getByRole("banner");
    const style = getComputedStyle(header);

    // Root cause of the overlap: the header was position:absolute (out of flow).
    // It must stay in normal flow and reserve its own space (never shrink) so it
    // cannot float over the scroll region. Assert the longhands rather than the
    // `flex` shorthand string, which jsdom may serialize differently.
    expect(style.position).not.toBe("absolute");
    expect(style.flexShrink).toBe("0");
  });

  it("lets the modal body scroll so content stays reachable at short viewports", () => {
    renderModal();

    const body = screen.getByRole("main");

    // Was overflow:hidden, which clipped the input/disclaimer with no scroll path.
    expect(getComputedStyle(body).overflowY).toBe("auto");
  });
});
