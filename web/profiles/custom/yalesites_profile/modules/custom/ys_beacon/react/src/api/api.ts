import { ConversationRequest } from "./models";
import { getWidgetAttribute } from "./widget";

export async function conversationApi(
  options: ConversationRequest,
  abortSignal: AbortSignal
): Promise<Response> {
  // Gets the conversation endpoint from Drupal via a data attribute.
  const conversationUrl =
    getWidgetAttribute("data-conversation-url") ||
    "/api/ys-beacon/v1/conversation";

  return fetch(conversationUrl, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ messages: options.messages }),
    signal: abortSignal,
  });
}
