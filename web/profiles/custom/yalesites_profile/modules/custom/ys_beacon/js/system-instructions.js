/**
 * @file
 * JavaScript for the Beacon system instructions admin interface.
 */

(function systemInstructionsInit($, Drupal, drupalSettings, once) {
  /**
   * Character counter behavior for the system instructions textarea.
   */
  Drupal.behaviors.systemInstructionsCharacterCount = {
    attach(context) {
      const textareas = once(
        "character-count",
        "textarea[data-maxlength]",
        context
      );

      textareas.forEach(function processTextarea(element) {
        const $textarea = $(element);
        const maxLength = parseInt($textarea.attr("data-maxlength"), 10);
        const warningThreshold =
          drupalSettings.ysBeaconSystemInstructions?.warningThreshold ??
          maxLength;

        // The form renders the counter span in the textarea description.
        const $counter = $("#instructions-character-count");

        function updateCounter() {
          const currentLength = $textarea.val().length;
          const remaining = maxLength - currentLength;

          $counter.text(
            Drupal.t(
              "Content recommended length set to @max characters, remaining: @remaining",
              {
                "@max": maxLength,
                "@remaining": remaining,
              }
            )
          );

          $textarea.toggleClass(
            "warning",
            currentLength > warningThreshold && currentLength <= maxLength
          );
          $textarea.toggleClass("error", currentLength > maxLength);
        }

        // Initialize counter
        updateCounter();

        // Update counter on input
        $textarea.on("input", updateCounter);
      });
    },
  };
})(jQuery, Drupal, drupalSettings, once);
