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

describe("Message grouping for screen readers", () => {
  const conversationWithTurn = (): Conversation => ({
    id: "conversation-1",
    title: "Office hours",
    messages: [
      {
        id: "message-user",
        role: "user",
        content: "What are the office hours?",
        date: "2026-01-01T00:00:00Z",
      },
      {
        id: "message-assistant",
        role: "assistant",
        content: "Office hours are 9am to 5pm.",
        date: "2026-01-01T00:00:00Z",
      },
    ],
    date: "2026-01-01T00:00:00Z",
  });

  it("groups the user message with an identifying aria-label", () => {
    renderChat({ currentChat: conversationWithTurn() });

    expect(
      screen.getByRole("group", { name: "user message" })
    ).toBeInTheDocument();
  });

  it("groups the Beacon response with an identifying aria-label", () => {
    renderChat({ currentChat: conversationWithTurn() });

    expect(
      screen.getByRole("group", { name: "Beacon response" })
    ).toBeInTheDocument();
  });
});

describe("Citation panel ARIA", () => {
  // The citation Modal portals into #ys-beacon-chat-widget; without it the modal
  // renders into a detached node and screen queries cannot see it.
  beforeEach(() => {
    const widget = document.createElement("div");
    widget.id = "ys-beacon-chat-widget";
    document.body.appendChild(widget);
  });

  afterEach(() => {
    document.getElementById("ys-beacon-chat-widget")?.remove();
  });

  // A [tool, assistant] turn: the tool message carries the citation payload and
  // the assistant answer references it with [doc1], so Answer renders a
  // clickable "Citation 1" button that opens the citation modal.
  const conversationWithCitation = (): Conversation => ({
    id: "conversation-1",
    title: "Office hours",
    messages: [
      {
        id: "message-tool",
        role: "tool",
        content: JSON.stringify({
          citations: [
            {
              content: "Office hours are 9am to 5pm.",
              id: "1",
              title: "Example Source",
              filepath: null,
              url: "https://example.com",
              metadata: null,
              chunk_id: null,
              reindex_id: null,
            },
          ],
          intent: "",
        }),
        date: "2026-01-01T00:00:00Z",
      },
      {
        id: "message-assistant",
        role: "assistant",
        content: "Office hours are listed in the handbook. [doc1]",
        date: "2026-01-01T00:00:00Z",
      },
    ],
    date: "2026-01-01T00:00:00Z",
  });

  it("opens the citation modal without exposing an orphan tabpanel", async () => {
    renderChat({ currentChat: conversationWithCitation() });

    await userEvent.click(screen.getByRole("button", { name: "Citation 1" }));

    // The modal opens and shows the citation content.
    expect(screen.getByRole("dialog")).toBeInTheDocument();
    expect(screen.getByText("Example Source")).toBeInTheDocument();

    // There is no tablist/tab in the widget, so role="tabpanel" is an invalid
    // orphan pattern (and its tabIndex added a confusing extra tab stop). The
    // citation container must not be exposed as a tabpanel.
    expect(screen.queryByRole("tabpanel")).not.toBeInTheDocument();
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

describe("Chat input describedby with no configured disclaimer (#1441)", () => {
  // No data-disclaimer set: the disclaimer element would render empty, so
  // aria-describedby must not point at an empty element (WCAG 1.3.1).
  beforeEach(() => {
    const widget = document.createElement("div");
    widget.id = "ys-beacon-chat-widget";
    document.body.appendChild(widget);
  });

  afterEach(() => {
    document.getElementById("ys-beacon-chat-widget")?.remove();
  });

  it("does not wire aria-describedby or render an empty disclaimer when none is configured", () => {
    renderChat();

    const input = screen.getByRole("textbox");
    expect(input.getAttribute("aria-describedby")).toBeNull();
    expect(document.getElementById("ys-beacon-chat-disclaimer")).toBeNull();
  });
});

describe("Citation overlay accessibility (#1441)", () => {
  beforeEach(() => {
    const widget = document.createElement("div");
    widget.id = "ys-beacon-chat-widget";
    document.body.appendChild(widget);
  });

  afterEach(() => {
    document.getElementById("ys-beacon-chat-widget")?.remove();
  });

  // Citation content carries a markdown heading so the demote-heading behaviour
  // (no injected page-level <h1>) can be asserted.
  const conversationWithRichCitation = (): Conversation => ({
    id: "conversation-1",
    title: "Office hours",
    messages: [
      {
        id: "message-tool",
        role: "tool",
        content: JSON.stringify({
          citations: [
            {
              content: "# Injected Source Heading\n\nOffice hours are 9am to 5pm.",
              id: "1",
              title: "Example Source",
              filepath: null,
              url: "https://example.com",
              metadata: null,
              chunk_id: null,
              reindex_id: null,
            },
          ],
          intent: "",
        }),
        date: "2026-01-01T00:00:00Z",
      },
      {
        id: "message-assistant",
        role: "assistant",
        content: "Office hours are listed in the handbook. [doc1]",
        date: "2026-01-01T00:00:00Z",
      },
    ],
    date: "2026-01-01T00:00:00Z",
  });

  const openCitation = async () => {
    await userEvent.click(screen.getByRole("button", { name: "Citation 1" }));
  };

  it("names the overlay dialog and titles it with a heading (WCAG 4.1.2)", async () => {
    renderChat({ currentChat: conversationWithRichCitation() });

    await openCitation();

    expect(
      screen.getByRole("dialog", { name: "Citations" })
    ).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Citations" })
    ).toBeInTheDocument();
  });

  it("renders the citation source as a real external link that announces the new tab (WCAG 2.1.1/4.1.2/3.2.5)", async () => {
    renderChat({ currentChat: conversationWithRichCitation() });

    await openCitation();

    const link = screen.getByRole("link", { name: /Example Source/i });
    expect(link).toHaveAttribute("href", "https://example.com");
    expect(link).toHaveAttribute("target", "_blank");
    expect(link.getAttribute("rel") ?? "").toContain("noopener");
    expect(link).toHaveAccessibleName(/opens in a new tab/i);
    // The old fake <h5 role="link"> is gone: the title is not a heading.
    expect(
      screen.queryByRole("heading", { name: /Example Source/i })
    ).not.toBeInTheDocument();
  });

  // The citation url comes from the external RAG/search backend; only http(s)
  // sources become a clickable link so a non-http scheme can't reach href.
  const conversationWithScheme = (url: string): Conversation => ({
    id: "conversation-1",
    title: "Office hours",
    messages: [
      {
        id: "message-tool",
        role: "tool",
        content: JSON.stringify({
          citations: [
            {
              content: "Office hours are 9am to 5pm.",
              id: "1",
              title: "Example Source",
              filepath: null,
              url,
              metadata: null,
              chunk_id: null,
              reindex_id: null,
            },
          ],
          intent: "",
        }),
        date: "2026-01-01T00:00:00Z",
      },
      {
        id: "message-assistant",
        role: "assistant",
        content: "See the handbook. [doc1]",
        date: "2026-01-01T00:00:00Z",
      },
    ],
    date: "2026-01-01T00:00:00Z",
  });

  it("renders a non-http(s) citation source as plain text, never a link", async () => {
    // eslint-disable-next-line no-script-url
    renderChat({ currentChat: conversationWithScheme("javascript:alert(1)") });

    await openCitation();

    expect(screen.getByText("Example Source")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Example Source/i })).not.toBeInTheDocument();
  });

  it("does not inject a page-level <h1> from citation source content (WCAG 1.3.1/2.4.6)", async () => {
    renderChat({ currentChat: conversationWithRichCitation() });

    await openCitation();

    expect(document.querySelector("h1")).toBeNull();
    // The source's own top-level heading is demoted to nest under the dialog title.
    expect(
      screen.getByRole("heading", { name: "Injected Source Heading", level: 3 })
    ).toBeInTheDocument();
  });

  it("returns focus to the triggering citation button when the overlay closes (WCAG 2.4.3)", async () => {
    renderChat({ currentChat: conversationWithRichCitation() });

    const chip = screen.getByRole("button", { name: "Citation 1" });
    await userEvent.click(chip);
    expect(
      screen.getByRole("dialog", { name: "Citations" })
    ).toBeInTheDocument();

    await userEvent.click(
      screen.getByRole("button", { name: /close modal/i })
    );

    expect(chip).toHaveFocus();
  });
});
