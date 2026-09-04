import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import Chat from "./Chat";
import { AppStateContext, AppState } from "../../state/AppProvider";
import { Conversation } from "../../api";
import styles from "./Chat.module.css";

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

  it("shows the citation label before its numbered badge, without repeating the number", async () => {
    renderChat({ currentChat: conversationWithCitation() });

    const button = screen.getByRole("button", { name: "Citation 1" });
    // Visible text should read "Citation" followed by the badge "1" -- not a
    // leading "1" badge followed by a redundant "Citation 1" label.
    expect(button.textContent).toBe("Citation1");
    expect(button.lastElementChild).toHaveTextContent("1");
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

  it("names the overlay dialog and titles it with a heading identifying which citation was opened (WCAG 4.1.2)", async () => {
    renderChat({ currentChat: conversationWithRichCitation() });

    await openCitation();

    expect(
      screen.getByRole("dialog", { name: "Citation 1" })
    ).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Citation 1", level: 2 })
    ).toBeInTheDocument();
  });

  it("renders the citation source as a real external link (WCAG 2.1.1/4.1.2/2.4.4)", async () => {
    renderChat({ currentChat: conversationWithRichCitation() });

    await openCitation();

    const link = screen.getByRole("link", { name: /Example Source/i });
    expect(link).toHaveAttribute("href", "https://example.com");
    expect(link).toHaveAttribute("target", "_blank");
    expect(link.getAttribute("rel") ?? "").toContain("noopener");
    // The external / "opens in a new window" icon and screen-reader announcement
    // are added at runtime by linkpurpose (component-library-twig), site-wide, so
    // this component intentionally carries no icon or new-tab text of its own.
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

  // See allowLinkOutsideHeading in constants/markdownComponents.tsx for why a
  // heading whose whole text is a link has to render as plain text.
  const conversationWithLinkedHeadingCitation = (): Conversation => ({
    id: "conversation-1",
    title: "Image Banner",
    messages: [
      {
        id: "message-tool",
        role: "tool",
        content: JSON.stringify({
          citations: [
            {
              content: "# [Image Banner](https://example.com/deep-section)\n\nBody text about the banner.",
              id: "1",
              title: "Image Banner",
              filepath: null,
              url: "https://example.com/image-banner",
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
        content: "See the source. [doc1]",
        date: "2026-01-01T00:00:00Z",
      },
    ],
    date: "2026-01-01T00:00:00Z",
  });

  it("renders a heading that is itself a markdown link as plain text, not a duplicate link (WCAG 2.4.4)", async () => {
    renderChat({ currentChat: conversationWithLinkedHeadingCitation() });

    await openCitation();

    // Only the citation's own title link should be a real link; the body's
    // heading-that-is-a-link must render as plain, non-link text.
    expect(
      screen.getAllByRole("link", { name: /Image Banner/i })
    ).toHaveLength(1);
    expect(
      screen.getByRole("heading", { name: "Image Banner", level: 3 })
    ).toBeInTheDocument();
  });

  // See normalizeCitationMarkdown in utils/ for why stray leading whitespace
  // in the indexed text turns a setext heading into a code block plus an <hr>.
  const conversationWithIndentedHeadingCitation = (): Conversation => ({
    id: "conversation-1",
    title: "Spotlight - Landscape",
    messages: [
      {
        id: "message-tool",
        role: "tool",
        content: JSON.stringify({
          citations: [
            {
              content:
                "[ Spotlight - Landscape ](/blocks-for-visreg/spotlight-landscape)\n" +
                "-----------------------------------------------------------------\n" +
                "\n" +
                "       Spotlight - Landscape Heading\n" +
                "------------------------------\n" +
                "\n" +
                " Spotlight - Landscape Subheading\n",
              id: "1",
              title: "Spotlight - Landscape",
              filepath: null,
              url: "https://example.com/spotlight-landscape",
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
        content: "See the source. [doc1]",
        date: "2026-01-01T00:00:00Z",
      },
    ],
    date: "2026-01-01T00:00:00Z",
  });

  it("renders a stray-indented setext heading as a heading, not a code block", async () => {
    renderChat({ currentChat: conversationWithIndentedHeadingCitation() });

    await openCitation();

    const dialog = screen.getByRole("dialog", { name: "Citation 1" });

    expect(
      screen.getByRole("heading", {
        name: "Spotlight - Landscape Heading",
        level: 4,
      })
    ).toBeInTheDocument();
    // The monospace box and the stray rule the misparse produced are both gone.
    expect(dialog.querySelector("pre")).toBeNull();
    expect(dialog.querySelector("hr")).toBeNull();
    // The subheading stays ordinary paragraph text.
    expect(
      screen.getByText("Spotlight - Landscape Subheading").tagName
    ).toBe("P");
  });

  // Only a heading that is ENTIRELY a link is unwrapped. A heading mixing
  // prose with a genuine link must keep it, or the destination is lost with no
  // other way for the reader to reach it.
  const conversationWithProseAndLinkHeading = (): Conversation => ({
    id: "conversation-1",
    title: "Policy",
    messages: [
      {
        id: "message-tool",
        role: "tool",
        content: JSON.stringify({
          citations: [
            {
              content:
                "## See the [policy page](https://example.com/policy) for details\n\nBody.",
              id: "1",
              title: "Policy",
              filepath: null,
              url: "https://example.com/policy-source",
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
        content: "See the source. [doc1]",
        date: "2026-01-01T00:00:00Z",
      },
    ],
    date: "2026-01-01T00:00:00Z",
  });

  it("keeps a genuine link inside a heading that also contains prose", async () => {
    renderChat({ currentChat: conversationWithProseAndLinkHeading() });

    await openCitation();

    expect(
      screen.getByRole("link", { name: "policy page" })
    ).toHaveAttribute("href", "https://example.com/policy");
    expect(
      screen.getByRole("heading", { name: /See the policy page for details/ })
    ).toBeInTheDocument();
  });

  it("returns focus to the triggering citation button when the overlay closes (WCAG 2.4.3)", async () => {
    renderChat({ currentChat: conversationWithRichCitation() });

    const chip = screen.getByRole("button", { name: "Citation 1" });
    await userEvent.click(chip);
    expect(
      screen.getByRole("dialog", { name: "Citation 1" })
    ).toBeInTheDocument();

    await userEvent.click(
      screen.getByRole("button", { name: /close modal/i })
    );

    expect(chip).toHaveFocus();
  });
});

// Finds a rule nested inside a media query. jsdom never *applies* media queries,
// so asserting that the desktop row survived has to be done against the CSS text
// itself rather than a computed style.
const findMediaRule = (
  condition: string,
  selector: string
): CSSStyleRule | undefined => {
  for (const sheet of Array.from(document.styleSheets)) {
    for (const rule of Array.from(sheet.cssRules)) {
      const media = (rule as CSSMediaRule).media;
      if (!media?.mediaText.includes(condition)) continue;

      const match = Array.from((rule as CSSMediaRule).cssRules).find(
        nested => (nested as CSSStyleRule).selectorText === selector
      );
      if (match) return match as CSSStyleRule;
    }
  }

  return undefined;
};

describe("New chat / disclaimer layout (#1645)", () => {
  // Realistic sample text. jsdom does no layout, so nothing here is actually
  // length-sensitive -- the real narrow-viewport check is a browser one.
  const DISCLAIMER =
    "Responses may contain errors or omissions and should be verified against " +
    "official Yale sources.";

  beforeEach(() => {
    const widget = document.createElement("div");
    widget.id = "ys-beacon-chat-widget";
    widget.setAttribute("data-disclaimer", DISCLAIMER);
    document.body.appendChild(widget);
  });

  afterEach(() => {
    document.getElementById("ys-beacon-chat-widget")?.remove();
  });

  const actionsRow = () =>
    screen.getByRole("button", { name: "New chat" }).parentElement as HTMLElement;

  it("styles the actions row from the stylesheet, not an inline style", () => {
    renderChat();

    const row = actionsRow();

    // This is the regression fence for the defect itself: an inline style cannot
    // be overridden by a media query, which is what made the mobile layout
    // unfixable in the first place.
    expect(row.getAttribute("style")).toBeNull();
    expect(row).toHaveClass(styles.chatActions);
  });

  it("stacks the New chat button above a full-width disclaimer at narrow widths", () => {
    renderChat();

    // jsdom does not match `(min-width:800px)`, so the computed style here is the
    // mobile-first base rule -- the layout a phone gets. flex-start is what keeps
    // the button its natural size while letting the paragraph fill the panel;
    // centring would re-narrow the disclaimer, stretching would blow the button
    // out to full width.
    const computed = getComputedStyle(actionsRow());

    expect(computed.display).toBe("flex");
    expect(computed.flexDirection).toBe("column");
    expect(computed.alignItems).toBe("flex-start");
    // Carried over verbatim from the inline style this rule replaced, and the
    // two a refactor is most likely to drop silently.
    expect(computed.gap).toBe("1rem");
    expect(computed.width).toBe("100%");
  });

  it("restores the side-by-side desktop row at 800px and up", () => {
    renderChat();

    const desktopRule = findMediaRule(
      "(min-width:800px)",
      `.${styles.chatActions}`
    );

    expect(desktopRule, "no min-width:800px rule for the actions row").toBeTruthy();
    // Only the two properties that differ from the mobile base belong here.
    // getPropertyValue, not the camelCase accessor: rules nested in a media
    // query use jsdom's plainer CSSOM declaration, which has no typed props.
    expect(desktopRule?.style.getPropertyValue("flex-direction")).toBe("row");
    expect(desktopRule?.style.getPropertyValue("align-items")).toBe("center");
  });

  it("keeps the disclaimer inside the actions row and still described-by the input", () => {
    renderChat();

    const describedById = screen
      .getByRole("textbox")
      .getAttribute("aria-describedby");
    const disclaimer = document.getElementById(describedById as string);

    // The restructure must not move the disclaimer out of the row or break the
    // screen-reader association with the question input.
    expect(actionsRow()).toContainElement(disclaimer);
  });
});
