((Drupal, once, $) => {
  Drupal.behaviors.ysViewsBasic = {
    attach: function (context) { // eslint-disable-line
      // Handle radio input checked behavior.
      function handleRadioInputs(radioGroup) {
        const radioInputs = document.querySelectorAll(radioGroup);
        radioInputs.forEach((input) => {
          input.addEventListener('change', function () {
            if (this.checked) {
              this.setAttribute('checked', 'checked');
              radioInputs.forEach((otherInput) => {
                if (otherInput !== this) {
                  otherInput.removeAttribute('checked');
                }
              });
            }
          });
        });
      }

      const radioGroups = [
        'input[name="settings[block_form][group_user_selection][entity_and_view_mode][entity_types]"]',
        'input[name="block_form[group_user_selection][entity_and_view_mode][entity_types]"]',
        'input[name="entity_types"]',
        'input[name="settings[block_form][group_user_selection][entity_and_view_mode][view_mode]"]',
        'input[name="block_form[group_user_selection][entity_and_view_mode][view_mode]"]',
        'input[name="view_mode"]',
        'input[name="settings[block_form][group_user_selection][filter_and_sort][term_operator]"',
        'input[name="block_form[group_user_selection][filter_and_sort][term_operator]"',
        'input[name="term_operator"]',
        'input[name="settings[block_form][group_user_selection][entity_specific][event_time_period]"]',
        'input[name="block_form[group_user_selection][entity_specific][event_time_period]"]',
        'input[name="event_time_period"]',
      ];

      radioGroups.forEach((group) => {
        handleRadioInputs(group);
      });

      // Handle limit display visibility and label.
      const editLimitWrapperElement = document.querySelector('#edit-limit');
      const displayElement = document.querySelector('select[name="settings[block_form][group_user_selection][options][display]"]');

      if (editLimitWrapperElement && displayElement) {
        const limitLabel = editLimitWrapperElement.querySelector('label');
        const updateLimitElement = () => {
          const value = displayElement.value;
          editLimitWrapperElement.setAttribute('style', value === 'all' ? 'display: none' : '');
          if (value === 'limit') {
            limitLabel.textContent = 'Items';
          } else if (value === 'pager') {
            limitLabel.textContent = 'Items Per Page';
          }
        };
        displayElement.addEventListener('change', updateLimitElement);
        updateLimitElement();
      }

      // Handle event time period visibility based on entity type and view mode.
      const entityTypesSelector = 'input[name="settings[block_form][group_user_selection][entity_and_view_mode][entity_types]"], input[name="block_form[group_user_selection][entity_and_view_mode][entity_types]"]';
      const viewModeSelector = 'input[name="settings[block_form][group_user_selection][entity_and_view_mode][view_mode]"], input[name="block_form[group_user_selection][entity_and_view_mode][view_mode]"]';
      const eventTimePeriod = document.querySelector('#edit-event-time-period');
      const entityTypes = document.querySelectorAll(entityTypesSelector);
      const viewModes = document.querySelectorAll(viewModeSelector);

      function updateVisibility() {
        if (eventTimePeriod) {
          const entityType = Array.from(entityTypes).find(input => input.checked)?.value;
          const viewMode = Array.from(viewModes).find(input => input.checked)?.value;
          eventTimePeriod.style.display = (entityType === 'event' && viewMode === 'calendar') ? 'none' : '';
        }
      }

      entityTypes.forEach(input => input.addEventListener('change', updateVisibility));
      viewModes.forEach(input => input.addEventListener('change', updateVisibility));
      updateVisibility();

      // Handle Enter key submission in event calendar filter form search field.
      const searchFields = once(
        'event-calendar-search-enter',
        'form#event-calendar-filter-form input[name="search"], form#event-calendar-filter-form .form-item-search input[type="text"]',
        context
      );

      searchFields.forEach((searchField) => {
        searchField.addEventListener('keydown', function(event) {
          if (event.key === 'Enter' || event.keyCode === 13) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const form = this.closest('form');
            const submitButton = form?.querySelector('input[type="submit"], button[type="submit"], .form-submit, .button--primary');
            if (submitButton) {
              $(submitButton).trigger('mousedown').trigger('click');
            }
          }
        });
      });
    },
  };
})(Drupal, once, jQuery);
