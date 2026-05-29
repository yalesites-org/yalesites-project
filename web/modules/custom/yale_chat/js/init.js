(function () {
  /**
   * Mounts the Yale Chat React widget and passes Drupal configuration to it
   * via data attributes on the root element.
   *
   * @see yale_chat_page_attachments_alter()
   */
  const chatWidget = document.createElement('div');
  chatWidget.setAttribute('id', 'yale-chat-widget');
  chatWidget.setAttribute(
    'data-initial-questions',
    drupalSettings.yale_chat.initial_questions || ''
  );
  chatWidget.setAttribute(
    'data-disclaimer',
    drupalSettings.yale_chat.disclaimer || ''
  );
  chatWidget.setAttribute(
    'data-footer',
    drupalSettings.yale_chat.footer || ''
  );
  chatWidget.setAttribute(
    'data-csrf-token',
    drupalSettings.yale_chat.csrf_token || ''
  );
  document.body.appendChild(chatWidget);
})();
