import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import Layout from "./Layout";
import { AppStateProvider } from "../../state/AppProvider";

const SCROLL_OFFSET = 120;

beforeEach(() => {
  // The widget mount element the Modal portals into at runtime.
  const widget = document.createElement("div");
  widget.id = "ys-beacon-chat-widget";
  document.body.appendChild(widget);

  Object.defineProperty(window, "scrollY", {
    value: SCROLL_OFFSET,
    configurable: true,
  });
  window.scrollTo = vi.fn();
});

afterEach(() => {
  document.getElementById("ys-beacon-chat-widget")?.remove();
  document.body.removeAttribute("data-body-frozen");
  document.body.removeAttribute("data-modal-active");
  document.body.style.top = "";
});

const renderLayout = () =>
  render(
    <AppStateProvider>
      <Layout />
    </AppStateProvider>
  );

describe("Layout body scroll lock", () => {
  it("does not freeze the body before the modal is opened", () => {
    renderLayout();

    expect(document.body.hasAttribute("data-body-frozen")).toBe(false);
    expect(document.body.style.top).toBe("");
  });

  it("freezes the body and pins the current scroll offset when the modal opens", async () => {
    renderLayout();

    await userEvent.click(
      screen.getByRole("button", { name: /try beacon now/i })
    );

    expect(document.body.getAttribute("data-body-frozen")).toBe("true");
    expect(document.body.getAttribute("data-modal-active")).toBe("true");
    expect(document.body.style.top).toBe(`-${SCROLL_OFFSET}px`);
  });

  it("unfreezes the body and restores the scroll position when the modal closes", async () => {
    renderLayout();

    await userEvent.click(
      screen.getByRole("button", { name: /try beacon now/i })
    );
    // Layout closes the modal on Escape.
    await userEvent.keyboard("{Escape}");

    expect(document.body.hasAttribute("data-body-frozen")).toBe(false);
    expect(document.body.hasAttribute("data-modal-active")).toBe(false);
    expect(document.body.style.top).toBe("");
    expect(window.scrollTo).toHaveBeenCalledWith(0, SCROLL_OFFSET);
  });
});

describe("Layout background inert while the modal is open (#1441)", () => {
  it("marks the underlying page inert on open and restores it on close, leaving the widget host interactive", async () => {
    // Stand-in for the host page's content, a sibling of the widget mount.
    const pageContent = document.createElement("div");
    pageContent.id = "page-content-under-test";
    document.body.appendChild(pageContent);

    try {
      renderLayout();
      expect(pageContent.hasAttribute("inert")).toBe(false);

      await userEvent.click(
        screen.getByRole("button", { name: /try beacon now/i })
      );

      // Background page is inert (removed from the a11y tree + tab order) so the
      // dialog is a true modal context (WCAG 1.3.1 / 4.1.2).
      expect(pageContent.hasAttribute("inert")).toBe(true);
      // The Beacon widget host must stay interactive — it holds the modal.
      expect(
        document
          .getElementById("ys-beacon-chat-widget")
          ?.hasAttribute("inert")
      ).toBe(false);

      // Layout closes the modal on Escape.
      await userEvent.keyboard("{Escape}");
      expect(pageContent.hasAttribute("inert")).toBe(false);
    } finally {
      pageContent.remove();
    }
  });
});
