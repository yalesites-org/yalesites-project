/**
 * Reads a configuration attribute from the widget mount element that the
 * Drupal module's init.js creates. This is the single home for the element
 * id and data-attribute contract shared with init.js.
 */
export function getWidgetAttribute(name: string): string {
  return (
    document.getElementById("ys-beacon-chat-widget")?.getAttribute(name) ?? ""
  );
}
