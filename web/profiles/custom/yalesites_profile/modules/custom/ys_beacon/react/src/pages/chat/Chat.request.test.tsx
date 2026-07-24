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
