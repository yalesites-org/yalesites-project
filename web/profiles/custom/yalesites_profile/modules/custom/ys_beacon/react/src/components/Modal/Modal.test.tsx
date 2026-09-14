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
      ariaLabel="Beacon chat"
    >
      <p>Chat body</p>
    </Modal>
  );

// The Modal portals out of the render container into #ys-beacon-chat-widget, so
// the header is queried from the document rather than the render container.
const modalHeader = () => document.querySelector("header") as HTMLElement;

describe("Modal accessible name", () => {
  it("names the dialog with the provided ariaLabel (WCAG 4.1.2)", () => {
    renderModal();

    expect(
      screen.getByRole("dialog", { name: "Beacon chat" })
    ).toBeInTheDocument();
  });
});

describe("Modal header landmark", () => {
  it("does not mark the modal header as a banner landmark (WCAG 1.3.1)", () => {
    renderModal();

    // A banner here creates a second banner landmark competing with the host
    // page header; the modal header must be a plain, unroled <header>. In a real
    // browser a <header> scoped inside the dialog's <section> is `generic`, not
    // `banner` (HTML-AAM) — testing-library's role engine does not implement that
    // scoping, so this asserts the explicit role is gone and the landmark tree is
    // verified live in the browser.
    const header = modalHeader();
    expect(header).not.toBeNull();
    expect(header.getAttribute("role")).toBeNull();
  });
});

describe("Modal close button", () => {
  it("renders the close button inside the modal header", () => {
    renderModal();

    const closeButton = within(modalHeader()).getByRole("button", {
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

describe("Modal focus management", () => {
  it("returns focus to the element that opened it when it closes (WCAG 2.4.3)", () => {
    const opener = document.createElement("button");
    opener.textContent = "Open";
    document.body.appendChild(opener);
    opener.focus();
    expect(opener).toHaveFocus();

    const { unmount } = renderModal();
    // The modal takes focus while open, then hands it back on close.
    unmount();

    expect(opener).toHaveFocus();
    opener.remove();
  });

  it("makes a modal already open beneath it inert while stacked, and restores it on close", () => {
    const first = render(
      <Modal show close={vi.fn()} header={<span>Chat</span>} footer={null} variant="" ariaLabel="Beacon chat">
        <p>Chat body</p>
      </Modal>
    );
    const firstSection = document.querySelector('[modal-is-open="true"]');
    expect(firstSection?.hasAttribute("inert")).toBe(false);

    // The citation overlay stacks on top of the chat modal.
    const second = render(
      <Modal show close={vi.fn()} header={<span>Citations</span>} footer={null} variant="citation" ariaLabel="Citations">
        <p>Citation body</p>
      </Modal>
    );
    expect(document.querySelectorAll('[modal-is-open="true"]').length).toBe(2);
    expect(firstSection?.hasAttribute("inert")).toBe(true);

    second.unmount();
    expect(firstSection?.hasAttribute("inert")).toBe(false);
    first.unmount();
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

    const style = getComputedStyle(modalHeader());

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
