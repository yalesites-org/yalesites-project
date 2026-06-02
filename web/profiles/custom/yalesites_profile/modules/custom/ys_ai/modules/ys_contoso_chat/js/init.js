(function () {
  /**
   * Mounts the Yale Chat React widget and passes Drupal configuration to it
   * via data attributes on the root element.
   *
   * @see ys_contoso_chat_page_attachments_alter()
   */
  const chatWidget = document.createElement("div");
  chatWidget.setAttribute("id", "yale-chat-widget");
  chatWidget.setAttribute(
    "data-initial-questions",
    drupalSettings.ys_contoso_chat.initial_questions || ""
  );
  chatWidget.setAttribute(
    "data-disclaimer",
    drupalSettings.ys_contoso_chat.disclaimer || ""
  );
  chatWidget.setAttribute(
    "data-footer",
    drupalSettings.ys_contoso_chat.footer || ""
  );
  document.body.appendChild(chatWidget);
})();
