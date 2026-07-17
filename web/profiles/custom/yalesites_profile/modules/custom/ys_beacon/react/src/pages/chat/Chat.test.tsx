import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
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

describe("Chat input accessibility wiring", () => {
  beforeEach(() => {
    const widget = document.createElement("div");
    widget.id = "ys-beacon-chat-widget";
    widget.setAttribute(
      "data-disclaimer",
      "AI generated content may be incorrect."
    );
    document.body.appendChild(widget);
  });

  afterEach(() => {
    document.getElementById("ys-beacon-chat-widget")?.remove();
  });

  it("points the question input's aria-describedby at the rendered disclaimer", () => {
    renderChat();

    const input = screen.getByRole("textbox");
    const describedById = input.getAttribute("aria-describedby");
    expect(describedById).toBeTruthy();

    const disclaimer = document.getElementById(describedById as string);
    expect(disclaimer).toBeInTheDocument();
    expect(disclaimer).toHaveTextContent("AI generated content may be incorrect.");
  });
});
