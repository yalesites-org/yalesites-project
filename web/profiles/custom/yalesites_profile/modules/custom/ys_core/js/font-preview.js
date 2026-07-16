(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.fontPreview = {
    attach: function (context, settings) {
      once('font-preview', '.font-pairing-selector', context).forEach(function (element) {
        // Show initial preview based on default selection
        const $selectedRadio = $('input[name="font_pairing"]:checked', element);
        if ($selectedRadio.length) {
          showPreview($selectedRadio.val());
        }

        // Update preview when selection changes
        $(element).on('change', 'input[name="font_pairing"]', function() {
          showPreview($(this).val());
        });
      });

      function showPreview(fontPairing) {
        $('.font-preview').removeClass('is-active');
        $('.font-preview-' + fontPairing).addClass('is-active');
      }
    }
  };
})(jQuery, Drupal, once);
