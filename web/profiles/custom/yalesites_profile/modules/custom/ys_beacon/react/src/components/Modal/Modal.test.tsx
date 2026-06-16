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
  it("renders the close button inside the modal header so it stays with the sticky header on mobile", () => {
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
