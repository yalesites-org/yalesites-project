(function ($, Drupal, once) {
  Drupal.behaviors.fontPreview = {
    attach(context, settings) {
      function showPreview(fontPairing) {
        $(".font-preview").removeClass("is-active");
        $(`.font-preview-${fontPairing}`).addClass("is-active");
      }

      once("font-preview", ".font-pairing-selector", context).forEach(function (
        element
      ) {
        // Show initial preview based on default selection
        const $selectedRadio = $('input[name="font_pairing"]:checked', element);
        if ($selectedRadio.length) {
          showPreview($selectedRadio.val());
        }

        // Update preview when selection changes
        $(element).on("change", 'input[name="font_pairing"]', function () {
          showPreview($(this).val());
        });
      });
    },
  };
})(jQuery, Drupal, once);
