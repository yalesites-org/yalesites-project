import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import Chat from "./Chat";
import { AppStateContext, AppState } from "../../state/AppProvider";
import { conversationApi } from "../../api";

// Replace only the network call; everything else in the api barrel (widget
// attribute reads, etc.) stays real.
vi.mock("../../api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("../../api")>();
  return { ...actual, conversationApi: vi.fn() };
});

// Builds a Response-like object with a readable body AND text(), so both the
// streaming reader path (if the guard were removed) and the guard's own
// body-parse path see the same bytes.
const streamResponse = (status: number, bodyText: string): Response =>
  ({
    ok: status >= 200 && status < 300,
    status,
    text: () => Promise.resolve(bodyText),
    body: new ReadableStream<Uint8Array>({
      start(controller) {
        controller.enqueue(new TextEncoder().encode(bodyText));
        controller.close();
      },
    }),
  }) as unknown as Response;

const renderChat = (state: AppState = { currentChat: null }, dispatch = vi.fn()) =>
  render(
    <AppStateContext.Provider value={{ state, dispatch }}>
      <Chat />
    </AppStateContext.Provider>
  );

const ask = () =>
  userEvent.type(
    screen.getByRole("textbox", { name: /ask a question/i }),
    "Hello{Enter}"
  );

afterEach(() => {
  vi.clearAllMocks();
});

describe("Conversation request error handling", () => {
  it("shows the standard error (not a blank answer) when the body is a non-JSON error page", async () => {
    // A proxy 5xx returning HTML: no parseable error envelope. Without the
    // guard the reader loop would swallow the unparseable body and finish on a
    // blank assistant bubble.
    vi.mocked(conversationApi).mockResolvedValue(
      streamResponse(502, "<html><body>502 Bad Gateway</body></html>")
    );

    renderChat();
    await ask();

    expect(await screen.findByText(/an error occurred/i)).toBeInTheDocument();
    expect(
      screen.queryByRole("group", { name: "Beacon response" })
    ).not.toBeInTheDocument();
  });

  it("surfaces the server's specific message from a non-OK JSON error body", async () => {
    // The controller's own guards return a non-OK status with {"error": "..."}.
    vi.mocked(conversationApi).mockResolvedValue(
      streamResponse(429, JSON.stringify({ error: "Too many requests. Please try again shortly." }))
    );

    renderChat();
    await ask();

    expect(
      await screen.findByText(/too many requests\. please try again shortly\./i)
    ).toBeInTheDocument();
  });
});

describe("Conversation request payload", () => {
  it("excludes prior tool/citation messages (and their source text) from the request payload", async () => {
    vi.mocked(conversationApi).mockResolvedValue(
      streamResponse(
        200,
        JSON.stringify({
          id: "r1",
          model: "m",
          created: 0,
          object: "chat.completion.chunk",
          choices: [{ messages: [{ role: "assistant", content: "ok" }] }],
        })
      )
    );

    // A prior turn's citation payload: the source text is large and, before the
    // fix, was re-uploaded on every subsequent turn until the body blew past the
    // server's size cap.
    const blob = "x".repeat(70000);
    const priorChat = {
      id: "c1",
      title: "prior",
      date: "2026-01-01T00:00:00.000Z",
      messages: [
        { id: "u1", role: "user", content: "First question", date: "2026-01-01T00:00:00.000Z" },
        {
          id: "t1",
          role: "tool",
          content: JSON.stringify({ citations: [{ content: blob }], intent: "First question" }),
          date: "2026-01-01T00:00:00.000Z",
        },
        { id: "a1", role: "assistant", content: "First answer", date: "2026-01-01T00:00:00.000Z" },
      ],
    };

    renderChat({ currentChat: priorChat } as unknown as AppState);
    await ask();

    const [sentRequest] = vi.mocked(conversationApi).mock.calls[0];
    const roles = sentRequest.messages.map((m) => m.role);
    // Tool/citation messages stay in local state for rendering, but must never
    // be re-uploaded: the server discards them and they overflow the size cap.
    expect(roles).not.toContain("tool");
    expect(roles).toEqual(["user", "assistant", "user"]);
    expect(JSON.stringify(sentRequest.messages)).not.toContain(blob);
  });
});
