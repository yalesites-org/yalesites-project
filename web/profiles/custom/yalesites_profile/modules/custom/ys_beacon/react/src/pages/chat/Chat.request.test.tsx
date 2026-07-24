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

const renderChat = (state: AppState = { currentChat: null }, dispatch = vi.fn()) =>
  render(
    <AppStateContext.Provider value={{ state, dispatch }}>
      <Chat />
    </AppStateContext.Provider>
  );

afterEach(() => {
  vi.clearAllMocks();
});

describe("Conversation request error handling", () => {
  it("surfaces the standard error on a non-OK response instead of a blank answer", async () => {
    // A proxy 5xx or empty body: response.ok is false and there is no
    // parseable error envelope in the body.
    vi.mocked(conversationApi).mockResolvedValue({
      ok: false,
      status: 502,
      body: null,
    } as unknown as Response);

    renderChat();

    await userEvent.type(
      screen.getByRole("textbox", { name: /ask a question/i }),
      "Hello{Enter}"
    );

    expect(await screen.findByText(/an error occurred/i)).toBeInTheDocument();
    // No blank Beacon answer bubble should have been rendered.
    expect(
      screen.queryByRole("group", { name: "Beacon response" })
    ).not.toBeInTheDocument();
  });
});
