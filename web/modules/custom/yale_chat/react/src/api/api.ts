import { ChatMessage, Conversation, ConversationRequest } from './models';

const WIDGET_ID = 'yale-chat-widget';

function getCsrfToken(): string {
  return document.getElementById(WIDGET_ID)?.getAttribute('data-csrf-token') ?? '';
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
  return fetch('/yale-chat/message', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken(),
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
  await fetch('/yale-chat/clear', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken(),
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
