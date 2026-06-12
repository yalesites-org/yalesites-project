(function initBeaconWidget() {
  /**
   * Initialization script for the Beacon chat feature.
   * This script creates and initializes the chat widget area on the webpage.
   * It relies on the drupalSettings object to configure the chat widget.
   *
   * @see ys_beacon_page_attachments_alter().
   */
  const chatWidget = document.createElement("div");
  chatWidget.setAttribute("id", "ys-beacon-chat-widget");
  chatWidget.setAttribute(
    "data-conversation-url",
    drupalSettings.ys_beacon.conversation_url || ""
  );
  chatWidget.setAttribute(
    "data-initial-questions",
    drupalSettings.ys_beacon.initial_questions || ""
  );
  chatWidget.setAttribute(
    "data-disclaimer",
    drupalSettings.ys_beacon.disclaimer || ""
  );
  chatWidget.setAttribute("data-footer", drupalSettings.ys_beacon.footer || "");
  document.body.appendChild(chatWidget);
})();
