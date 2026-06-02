import { ChatMessage, Conversation, ConversationRequest } from './models';

let csrfTokenPromise: Promise<string> | null = null;

/**
 * Fetch a session CSRF token from Drupal at runtime.
 *
 * The token must not be embedded in the (cacheable) page markup — a cached
 * token does not match the visitor's session and is rejected. Drupal's
 * /session/token endpoint returns a fresh token for the current session and
 * is never cached. The result is memoised for the page load.
 */
function getCsrfToken(): Promise<string> {
  if (!csrfTokenPromise) {
    csrfTokenPromise = fetch('/session/token', { credentials: 'same-origin' })
      .then((r) => (r.ok ? r.text() : ''))
      .catch(() => '');
  }
  return csrfTokenPromise;
}

/**
 * Send a conversation turn to the Drupal backend and receive a streamed reply.
 *
 * POST /yale-chat/message returns newline-delimited JSON chunks in Azure
 * OpenAI format so the Chat component's stream-reading logic is unchanged.
 */
export async function conversationApi(
  options: ConversationRequest,
  abortSignal: AbortSignal,
  conversationId?: string,
): Promise<Response> {
  const csrfToken = await getCsrfToken();
  return fetch('/yale-chat/message', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken,
    },
    body: JSON.stringify({
      messages: options.messages,
      conversation_id: conversationId,
    }),
    signal: abortSignal,
  });
}

/**
 * Clear the server-side session thread for a conversation.
 */
export async function clearConversation(conversationId: string): Promise<void> {
  const csrfToken = await getCsrfToken();
  await fetch('/yale-chat/clear', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken,
    },
    body: JSON.stringify({ conversation_id: conversationId }),
  });
}

// ---------------------------------------------------------------------------
// Stubs for Azure/CosmosDB APIs imported by copied components.
// These are never called at runtime — CosmosDB is always disabled.
// ---------------------------------------------------------------------------

export const fetchChatHistoryInit = (): Conversation[] | null => null;

export const historyList = async (_offset = 0): Promise<Conversation[] | null> => null;

export const historyRead = async (_convId: string): Promise<ChatMessage[]> => [];

export const historyGenerate = async (
  _options: ConversationRequest,
  _signal: AbortSignal,
  _convId?: string,
): Promise<Response> => new Response();

export const historyUpdate = async (_messages: ChatMessage[], _convId: string): Promise<Response> =>
  new Response();

export const historyDelete = async (_convId: string): Promise<Response> => new Response();

export const historyDeleteAll = async (): Promise<Response> => new Response();

export const historyClear = async (_convId: string): Promise<Response> => new Response();

export const historyRename = async (_convId: string, _title: string): Promise<Response> =>
  new Response();

export const historyEnsure = async () => ({
  cosmosDB: false,
  status: 'CosmosDB is not configured',
});

export const frontendSettings = async (): Promise<Response | null> => null;

export const historyMessageFeedback = async (
  _messageId: string,
  _feedback: string,
): Promise<Response> => new Response();
