import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
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

// The launch button is aria-hidden (YaleSites-Internal#1944), which leaves it no accessible name,
// so look it up by the id js/events.js uses and check its visible text.
const getLaunchButton = () => {
  const button = document.getElementById("ys-beacon-launch-modal");
  expect(button?.tagName).toBe("BUTTON");
  expect(button).toHaveTextContent(/try beacon now/i);
  return button as HTMLElement;
};

describe("Layout body scroll lock", () => {
  it("does not freeze the body before the modal is opened", () => {
    renderLayout();

    expect(document.body.hasAttribute("data-body-frozen")).toBe(false);
    expect(document.body.style.top).toBe("");
  });

  it("freezes the body and pins the current scroll offset when the modal opens", async () => {
    renderLayout();

    await userEvent.click(
      getLaunchButton()
    );

    expect(document.body.getAttribute("data-body-frozen")).toBe("true");
    expect(document.body.getAttribute("data-modal-active")).toBe("true");
    expect(document.body.style.top).toBe(`-${SCROLL_OFFSET}px`);
  });

  it("unfreezes the body and restores the scroll position when the modal closes", async () => {
    renderLayout();

    await userEvent.click(
      getLaunchButton()
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
        getLaunchButton()
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

describe("Layout launch button is a programmatic hook only (#1944)", () => {
  it("hides the launch button from assistive technology and the tab order", () => {
    renderLayout();

    const button = document.getElementById("ys-beacon-launch-modal");
    expect(button).not.toBeNull();
    expect(button?.getAttribute("aria-hidden")).toBe("true");
    expect(button?.getAttribute("tabindex")).toBe("-1");
    // Not exposed to AT, so a role query without hidden: true finds nothing.
    expect(
      screen.queryByRole("button", { name: /try beacon now/i })
    ).toBeNull();
  });

  it("still opens the modal when clicked programmatically, as js/events.js does", async () => {
    renderLayout();

    const button = document.getElementById("ys-beacon-launch-modal");
    act(() => {
      button?.click();
    });

    expect(document.body.getAttribute("data-modal-active")).toBe("true");
    expect(await screen.findByRole("dialog")).toBeTruthy();
  });
});
