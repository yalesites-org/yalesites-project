/**
 * @file
 * JavaScript behaviors for the Book module.
 */

(function ($, Drupal) {
  /**
   * Adds summaries to the book outline form.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches summary behavior to book outline forms.
   */
  Drupal.behaviors.ysCoreBookChanges = {
    attach(context) {
      const $select = $(context).find(".book-title-select");
      $select
        .find("option")
        .filter(function findCreateNewBookOption() {
          return $(this).text().trim() === "- Create a new book -";
        })
        .text(Drupal.t("- Create a new collection -"));

      const $messageWrapper = $("#edit-book-plid-wrapper", context);
      const $messageText = $messageWrapper.find("em");

      function updateMessage() {
        const val = $select.val();
        const defaultMessage = Drupal.t(
          "This will be the top-level page in this collection."
        );
        if (val === "0") {
          $messageText.text(Drupal.t("No collection selected."));
        } else if (val === "new") {
          $messageText.text(defaultMessage);
        } else {
          $messageText.text(defaultMessage);
        }
      }

      function updateDescription() {
        $(context)
          .find('[id^="edit-book-pid"][id$="description"]')
          .each(function () {
            const $description = $(this);
            $description.html((index, html) =>
              html.replace(/\bbook\b/gi, Drupal.t("collection"))
            );
          });
      }

      // Update message on page load
      updateMessage();
      updateDescription();

      // Update message when the select value changes
      $select.on("change", () => {
        updateMessage();
        updateDescription();
      });

      $(context)
        .find(".book-outline-form")
        .drupalSetSummary((context) => {
          const val = $select[0].value;

          if (val === "0") {
            return Drupal.t("Not in collection");
          }
          if (val === "new") {
            return Drupal.t("New collection");
          }
          return Drupal.checkPlain($select.find(":selected")[0].textContent);
        });
    },
  };
})(jQuery, Drupal);
