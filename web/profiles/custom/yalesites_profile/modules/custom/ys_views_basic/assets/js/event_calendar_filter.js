(function ($, Drupal, once) {
  Drupal.behaviors.eventCalendarFilter = {
    attach: function (context, settings) {
      const elements = once('eventCalendarFilter', '.event-calendar-filter-form', context);
      elements.forEach(function (element) {
        var $form = $(element);
        var $wrapper = $form.closest('div[id^="event-calendar-filter-wrapper-"]');
        var wrapperId = $wrapper.attr('id');
        var action = $form.attr('action');

        // Debug: log wrapperId and action
        console.log('EventCalendarFilter: wrapperId =', wrapperId, 'action =', action);

        function submitFilterForm(e) {
          e.preventDefault();
          var formData = $form.serializeArray();
          // Add the wrapper id so the backend knows what to replace.
          formData.push({ name: 'calendar_id', value: '#' + wrapperId });
          // Optionally add month/year if present in the form or as data attributes.
          var month = $form.find('[name="month"]').val() || $form.data('month');
          var year = $form.find('[name="year"]').val() || $form.data('year');
          if (month) formData.push({ name: 'month', value: month });
          if (year) formData.push({ name: 'year', value: year });

          // Debug: log formData
          console.log('EventCalendarFilter: formData =', formData);

          $.ajax({
            url: action,
            type: 'POST',
            data: formData,
            success: function (response) {
              // Replace the wrapper with the returned markup.
              Drupal.Ajax.prototype.success(response, {}, $form[0]);
            },
            error: function (xhr) {
              // Optionally show an error message.
              alert('Error updating calendar.');
            }
          });
        }

        // Submit on form submit.
        $form.on('submit', submitFilterForm);
        // Optionally, submit on any select change for instant filtering.
        $form.find('select').on('change', submitFilterForm);
      });
    }
  };
})(jQuery, Drupal, once);
