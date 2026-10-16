(function ($, Drupal, once) {
  // Maps each select's form element name to the preview data attribute it
  // controls, so the preview stays correct however many of the three
  // controls a user changes.
  const CONTROLS = {
    heading_font: "data-heading-font",
    heading_numerals: "data-heading-numerals",
    body_numerals: "data-body-numerals",
  };

  Drupal.behaviors.fontPreview = {
    attach(context, settings) {
      once("font-preview", ".font-preview-container", context).forEach(
        function (container) {
          const $container = $(container);

          Object.keys(CONTROLS).forEach(function (name) {
            const attribute = CONTROLS[name];
            // Each preview only carries the attribute(s) it actually renders
            // from, so this scopes the listener to the previews that care.
            if (!$container.is(`[${attribute}]`)) {
              return;
            }

            $(`select[name="${name}"]`, context).on("change", function () {
              $container.attr(attribute, $(this).val());
            });
          });
        }
      );
    },
  };
})(jQuery, Drupal, once);
