/**
 * @file
 * Shows "Hidden from menu" in the node form's menu settings summary.
 */

(($, Drupal) => {
  /**
   * Overrides core's menuUiDetailsSummaries summary, which runs first.
   */
  Drupal.behaviors.ysCoreMenuLinkFormSummary = {
    attach(context) {
      $(context)
        .find(".menu-link-form")
        .drupalSetSummary((details) => {
          const $details = $(details);
          if (
            !$details.find(".js-form-item-menu-enabled input:checked").length
          ) {
            return Drupal.t("Not in menu");
          }
          if ($details.find(".js-form-item-menu-hidden input:checked").length) {
            return Drupal.t("Hidden from menu");
          }
          return Drupal.checkPlain(
            $details.find(".js-form-item-menu-title input")[0].value
          );
        });
    },
  };
})(jQuery, Drupal);
