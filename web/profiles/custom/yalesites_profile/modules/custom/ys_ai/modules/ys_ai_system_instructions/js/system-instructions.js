/**
 * @file
 * JavaScript for the system instructions admin interface.
 */

(function systemInstructionsInit($, Drupal, once) {
  /**
   * Auto-refresh behavior for loading state.
   */
  Drupal.behaviors.systemInstructionsAutoRefresh = {
    attach(context, settings) {
      if (settings.ysAiSystemInstructions?.autoRefresh) {
        // Auto-trigger the hidden refresh button after a short delay.
        setTimeout(function triggerRefresh() {
          const refreshButton = document.getElementById(
            "system-instructions-refresh-btn"
          );
          if (refreshButton) {
            refreshButton.click();
          }
        }, 1000); // 1 second delay to show the spinner briefly
      }
    },
  };

  /**
   * Character counter behavior for system instructions textarea.
   */
  Drupal.behaviors.systemInstructionsCharacterCount = {
    attach(context) {
      const textarea = once(
        "character-count",
        "textarea[data-maxlength]",
        context
      );

      textarea.forEach(function processTextarea(element) {
        const $textarea = $(element);
        const maxLength = parseInt($textarea.attr("data-maxlength"), 10);

        // Find the counter element (should already exist in DOM)
        let $counter = $("#instructions-character-count");
        if ($counter.length === 0) {
          // Fallback: create if not found
          $counter = $(
            '<div id="instructions-character-count" class="character-count"></div>'
          );
          $textarea.after($counter);
        }

        function updateCounter() {
          const currentLength = $textarea.val().length;
          const remaining = maxLength - currentLength;

          // Update counter text to match the quote field example format
          $counter.text(
            Drupal.t(
              "Content recommended length set to @max characters, remaining: @remaining",
              {
                "@max": maxLength,
                "@remaining": remaining,
              }
            )
          );

          // No color changes needed - it's just a recommendation
        }

        // Initialize counter
        updateCounter();

        // Update counter on input
        $textarea.on("input keyup paste", updateCounter);
      });
    },
  };
})(jQuery, Drupal, once);
