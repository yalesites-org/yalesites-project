/**
 * @file
 * Keeps Linkit's suggestion list usable in the Layout Builder dialogs.
 *
 * jQuery UI opens the list below its field once, with no height limit and no
 * collision handling, so a long list ran off the bottom of the window. This
 * caps the list at half the dialog, places it above the field when it does not
 * fit below, and shifts it left at the dialog's right edge. The list hangs off
 * the dialog's fixed frame rather than its scrolling body, so it is placed
 * again whenever that body scrolls, and closed once the field scrolls out of
 * sight.
 *
 * Exact title matches are also moved to the top, in CKEditor's link balloon
 * too. Linkit sorts suggestions A-Z, so a page titled exactly what the editor
 * typed could sit far down a long list.
 *
 * Handlers are delegated from the document because the dialogs, and Linkit's
 * own script, arrive over AJAX after this library loads.
 */
(($) => {
  const FIELD = 'input.form-linkit-autocomplete';

  // Space kept between the list and the edge of the dialog, in px.
  const EDGE_GAP = 8;

  // The field whose list is open, if any.
  let openField = null;

  /**
   * Places a field's open list against it, or closes it if out of sight.
   *
   * @param {HTMLInputElement} field
   *   The Linkit field.
   */
  function place(field) {
    const rect = field.getBoundingClientRect();
    const body = field.closest('.ui-dialog-content');
    if (body) {
      const seen = body.getBoundingClientRect();
      if (rect.bottom <= seen.top || rect.top >= seen.bottom) {
        $(field).autocomplete('close');
        return;
      }
    }
    const frame = field.closest('.ui-dialog');
    const room = frame
      ? frame.getBoundingClientRect().height
      : window.innerHeight;
    // At most half the frame beside the field, so the list always fits on one
    // side of it; jQuery UI's "flip" then picks that side.
    $(field)
      .autocomplete('widget')
      .css('max-height', Math.floor((room - rect.height) / 2) - EDGE_GAP)
      .position({
        my: 'left top',
        at: 'left bottom',
        of: field,
        collision: 'fit flip',
        within: frame || window,
      });
  }

  $(document)
    .on('autocompleteresponse', FIELD, (event, ui) => {
      const term = $(event.target).autocomplete('instance').term.trim();
      // Labels arrive HTML-escaped; DOMParser decodes without running markup.
      const isExact = (item) =>
        new DOMParser()
          .parseFromString(item.label, 'text/html')
          .body.textContent.trim()
          .toLowerCase() === term.toLowerCase();
      const exact = ui.content.filter(isExact);
      if (exact.length) {
        const rest = ui.content.filter((item) => !isExact(item));
        // ui.content is the array jQuery UI renders; reorder it in place.
        ui.content.splice(0, ui.content.length, ...exact, ...rest);
      }
    })
    .on('autocompleteopen', FIELD, (event) => {
      // CKEditor's link balloon lays out and caps its own list.
      if (event.target.closest('.ck')) {
        return;
      }
      openField = event.target;
      $(openField).autocomplete('widget').scrollTop(0);
      place(openField);
    })
    .on('autocompleteclose', FIELD, (event) => {
      if (openField === event.target) {
        openField = null;
      }
    });

  // Scroll events do not bubble, so listen in the capture phase.
  document.addEventListener(
    'scroll',
    (event) => {
      if (!openField) {
        return;
      }
      // The dialog can close or rebuild its form without closing the list.
      if (!openField.isConnected) {
        openField = null;
        return;
      }
      const body = openField.closest('.ui-dialog-content');
      if (body && event.target === body) {
        place(openField);
      }
    },
    { capture: true, passive: true },
  );
})(jQuery);
