(function ($, Drupal, once) {
  'use strict';

  /**
   * Event Calendar Filter Form behaviors.
   *
   * Re-initializes Chosen.js on filter form elements after AJAX updates.
   */
  Drupal.behaviors.eventCalendarFilters = {
    attach: function (context, settings) {
      // Process select elements in the filter form that haven't been processed yet.
      const elements = once('event-calendar-select', '.ys-filter-form select[multiple]', context);

      elements.forEach(function(selectElement) {
        const $select = $(selectElement);

        // Only handle elements that should use Chosen.js.
        if ($select.is('[data-drupal-selector]') && $select.attr('multiple')) {
          // Store current values before reinitialization.
          const currentValues = $select.val();

          // Initialize or update Chosen.js if available.
          if ($.fn.chosen) {
            if ($select.data('chosen')) {
              // Update existing Chosen instance.
              $select.trigger('chosen:updated');
            } else {
              // Initialize new Chosen instance.
              $select.chosen({
                width: '100%',
                placeholder_text_multiple: 'Select options...'
              });
            }

            // Restore values after initialization.
            if (currentValues && currentValues.length > 0) {
              $select.val(currentValues).trigger('chosen:updated');
            }
          }
        }
      });
    }
  };

})(jQuery, Drupal, once);
