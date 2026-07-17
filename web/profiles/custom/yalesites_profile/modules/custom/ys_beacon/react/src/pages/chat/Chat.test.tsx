import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import Chat from "./Chat";
import { AppStateContext, AppState } from "../../state/AppProvider";
import { Conversation } from "../../api";

const renderChat = (
  state: AppState = { currentChat: null },
  dispatch = vi.fn()
) =>
  render(
    <AppStateContext.Provider value={{ state, dispatch }}>
      <Chat />
    </AppStateContext.Provider>
  );

const conversationWith = (content: string): Conversation => ({
  id: "conversation-1",
  title: content,
  messages: [
    { id: "message-1", role: "user", content, date: "2026-01-01T00:00:00Z" },
  ],
  date: "2026-01-01T00:00:00Z",
});

describe("New chat button", () => {
  it("renders a labeled 'New chat' button instead of the old broom icon button", () => {
    renderChat();

    expect(
      screen.getByRole("button", { name: "New chat" })
    ).toBeInTheDocument();
    // The replaced FluentUI broom button used this aria-label.
    expect(screen.queryByLabelText("Start a new chat")).not.toBeInTheDocument();
  });

  it("is disabled when there are no messages", () => {
    renderChat();

    expect(screen.getByRole("button", { name: "New chat" })).toBeDisabled();
  });

  it("clears the current chat when clicked with an active conversation", async () => {
    const dispatch = vi.fn();
    renderChat({ currentChat: conversationWith("Hello") }, dispatch);

    const newChatButton = screen.getByRole("button", { name: "New chat" });
    expect(newChatButton).toBeEnabled();

    await userEvent.click(newChatButton);

    expect(dispatch).toHaveBeenCalledWith({
      type: "UPDATE_CURRENT_CHAT",
      payload: null,
    });
  });
});

describe("Initial question prompts", () => {
  // Configure MORE prompts than are shown so the test verifies both the order
  // AND the selection: the component must show the first four in configured
  // order, not a shuffled subset. A reintroduced random shuffle would render a
  // different subset and/or order and fail this, without relying on any
  // engine-specific Array.sort behavior.
  const configuredPrompts = [
    "First question",
    "Second question",
    "Third question",
    "Fourth question",
    "Fifth question",
    "Sixth question",
  ];
  const expectedVisiblePrompts = configuredPrompts.slice(0, 4);

  const mountWidgetWithPrompts = (prompts: string[]) => {
    const widget = document.createElement("div");
    widget.id = "ys-beacon-chat-widget";
    widget.setAttribute("data-initial-questions", JSON.stringify(prompts));
    document.body.appendChild(widget);
  };

  const renderedPrompts = () =>
    screen
      .getAllByRole("button")
      .map((button) => button.textContent ?? "")
      .map((text) => configuredPrompts.find((prompt) => text.includes(prompt)))
      .filter((prompt): prompt is string => Boolean(prompt));

  afterEach(() => {
    document.getElementById("ys-beacon-chat-widget")?.remove();
  });

  it("shows the first four configured prompts in configured order, not a shuffled subset", () => {
    mountWidgetWithPrompts(configuredPrompts);

    renderChat();

    expect(renderedPrompts()).toEqual(expectedVisiblePrompts);
  });
});
