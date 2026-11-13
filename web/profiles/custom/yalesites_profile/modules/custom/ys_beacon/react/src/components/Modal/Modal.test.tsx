import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
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

  it("focuses the dialog beneath, not <body>, when the stacked modal's opener is gone on close", () => {
    const first = render(
      <Modal show close={vi.fn()} header={<span>Chat</span>} footer={null} variant="" ariaLabel="Beacon chat">
        <button type="button">Citation 1</button>
      </Modal>
    );
    const firstSection = screen.getByRole("dialog", { name: "Beacon chat" });
    const opener = screen.getByRole("button", { name: "Citation 1" });
    opener.focus();

    const second = render(
      <Modal show close={vi.fn()} header={<span>Citations</span>} footer={null} variant="citation" ariaLabel="Citations">
        <p>Citation body</p>
      </Modal>
    );
    // The opener re-renders away while the overlay is open.
    opener.remove();

    second.unmount();

    expect(firstSection).toHaveFocus();
    first.unmount();
  });
});

// YaleSites-Internal#1945: Tab and Shift+Tab must cycle only through the
// dialog's controls, whatever currently has focus (WCAG 2.4.3 / 2.1.2).
describe("Modal focus trap (#1945)", () => {
  const renderWithControls = () =>
    render(
      <Modal show close={vi.fn()} header={<span>Header</span>} footer={<a href="/privacy">Privacy</a>} variant="" ariaLabel="Beacon chat">
        <button type="button">Prompt one</button>
        <button type="button" disabled>Disabled</button>
        <textarea aria-label="Ask a question" />
      </Modal>
    );

  // A page control outside the dialog, before the widget host in DOM order, so
  // a Tab that escapes the dialog has somewhere to land.
  let outside: HTMLButtonElement;
  beforeEach(() => {
    outside = document.createElement("button");
    outside.textContent = "Page control";
    document.body.insertBefore(outside, document.body.firstChild);
  });
  afterEach(() => outside.remove());

  const controls = () => [
    screen.getByRole("button", { name: /close modal/i }),
    screen.getByRole("button", { name: "Prompt one" }),
    screen.getByRole("textbox", { name: "Ask a question" }),
    screen.getByRole("link", { name: "Privacy" }),
  ];

  it("cycles forward through the dialog's controls and wraps from the last to the first", async () => {
    const user = userEvent.setup();
    renderWithControls();
    const [close, prompt, input, privacy] = controls();

    const visited: Element[] = [];
    for (let i = 0; i < 8; i++) {
      await user.tab();
      visited.push(document.activeElement as Element);
    }

    expect(visited).toEqual([close, prompt, input, privacy, close, prompt, input, privacy]);
  });

  it("cycles backward with Shift+Tab and wraps from the first to the last", async () => {
    const user = userEvent.setup();
    renderWithControls();
    const [close, prompt, input, privacy] = controls();
    close.focus();

    const visited: Element[] = [];
    for (let i = 0; i < 5; i++) {
      await user.tab({ shift: true });
      visited.push(document.activeElement as Element);
    }

    expect(visited).toEqual([privacy, input, prompt, close, privacy]);
  });

  it("moves Shift+Tab from the dialog itself to its last control, not out of the dialog", async () => {
    const user = userEvent.setup();
    renderWithControls();
    // The dialog takes focus when it opens.
    expect(screen.getByRole("dialog")).toHaveFocus();

    await user.tab({ shift: true });

    expect(screen.getByRole("link", { name: "Privacy" })).toHaveFocus();
  });

  it("keeps the dialog container itself out of the tab order", () => {
    renderWithControls();

    expect(screen.getByRole("dialog").getAttribute("tabindex")).toBe("-1");
  });

  it("pulls focus back into the dialog when Tab is pressed with focus outside it", async () => {
    const user = userEvent.setup();
    renderWithControls();
    const [close, , , privacy] = controls();

    (document.activeElement as HTMLElement).blur();
    expect(document.body).toHaveFocus();
    await user.tab();
    expect(close).toHaveFocus();

    outside.focus();
    await user.tab({ shift: true });
    expect(privacy).toHaveFocus();
  });

  it("puts focus back on the dialog when the focused control drops it", async () => {
    renderWithControls();
    const prompt = screen.getByRole("button", { name: "Prompt one" });
    prompt.focus();

    // What a browser does when the focused control is disabled or removed.
    prompt.blur();
    expect(document.body).toHaveFocus();

    await waitFor(() => expect(screen.getByRole("dialog")).toHaveFocus());
  });

  it("skips controls removed from the tab order with tabindex=-1", async () => {
    const user = userEvent.setup();
    render(
      <Modal show close={vi.fn()} header={<span>Header</span>} footer={<a href="/hidden" tabIndex={-1}>Hidden</a>} variant="" ariaLabel="Beacon chat">
        <a href="/privacy">Privacy</a>
      </Modal>
    );

    await user.tab({ shift: true });

    expect(screen.getByRole("link", { name: "Privacy" })).toHaveFocus();
  });

  it("leaves Tab handling to the top dialog when another modal is stacked on it", async () => {
    const user = userEvent.setup();
    renderWithControls();
    render(
      <Modal show close={vi.fn()} header={<span>Citations</span>} footer={null} variant="citation" ariaLabel="Citations">
        <a href="/source">Source</a>
      </Modal>
    );
    const citations = screen.getByRole("dialog", { name: "Citations" });
    const citationClose = within(citations).getByRole("button", { name: /close modal/i });
    const source = within(citations).getByRole("link", { name: "Source" });

    await user.tab();
    expect(citationClose).toHaveFocus();
    await user.tab();
    expect(source).toHaveFocus();
    await user.tab();
    expect(citationClose).toHaveFocus();
  });
});

describe("Modal restoreFocus (#1945)", () => {
  it("leaves focus alone on close when the parent restores it", () => {
    const opener = document.createElement("button");
    opener.textContent = "Open";
    document.body.appendChild(opener);
    opener.focus();

    try {
      const { unmount } = render(
        <Modal show close={vi.fn()} header={<span>Header</span>} footer={null} variant="" ariaLabel="Beacon chat" restoreFocus={false}>
          <p>Chat body</p>
        </Modal>
      );
      unmount();

      expect(opener).not.toHaveFocus();
    } finally {
      opener.remove();
    }
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
