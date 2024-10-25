((Drupal) => {
  Drupal.behaviors.ysViewsBasic = {
    attach: function () { // eslint-disable-line
      // Function to handle radio input checked behavior based on radio element selection.
      function handleRadioInputs(radioGroup) {
        // Get references to the radio input elements within the specified group
        const radioInputs = document.querySelectorAll(radioGroup);

        // Add event listener to each radio input
        radioInputs.forEach((input) => {
          input.addEventListener("change", function () {
            if (this.checked) {
              this.setAttribute("checked", "checked");
              // Remove the 'checked' attribute from other radio inputs
              radioInputs.forEach((otherInput) => {
                if (otherInput !== this) {
                  otherInput.removeAttribute("checked");
                }
              });
            }
          });
        });
      }

      // Store radio input groups in an array
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

      // Apply the function to each radio input group
      radioGroups.forEach((group) => {
        handleRadioInputs(group);
      });

      // Handle limit display
      const editLimitWrapperElement = document.querySelector('#edit-limit');
      const displayElement = document.querySelector('select[name="settings[block_form][group_user_selection][options][display]"');

      // If they're ever gone from the form, don't deal with this.
      if (editLimitWrapperElement && displayElement) {
        const limitLabel = editLimitWrapperElement.querySelector('label');

        const updateLimitElement = () => {
          const value = displayElement.value;

          // First evaluate whether to hide/show the limit
          const newLimitDisplayValue = value === 'all' ? 'display: none' : '';
          editLimitWrapperElement.setAttribute('style', newLimitDisplayValue);

          // Change the title
          switch (value) {
            case 'all':
              break;
            case 'limit':
              limitLabel.textContent = 'Items';
              break;
            case 'pager':
              limitLabel.textContent = 'Items Per Page';
            default:
              break;
          }
        }

        displayElement.addEventListener('change', updateLimitElement);
        updateLimitElement();
      }

      // Unified selectors to handle both cases
      const entityTypesSelector = 'input[name="settings[block_form][group_user_selection][entity_and_view_mode][entity_types]"], input[name="block_form[group_user_selection][entity_and_view_mode][entity_types]"]';
      const viewModeSelector = 'input[name="settings[block_form][group_user_selection][entity_and_view_mode][view_mode]"], input[name="block_form[group_user_selection][entity_and_view_mode][view_mode]"]';
      const eventTimePeriod = document.querySelector('#edit-event-time-period');

      const entityTypes = document.querySelectorAll(entityTypesSelector);
      const viewModes = document.querySelectorAll(viewModeSelector);

      // Function to handle visibility based on conditions
      function updateVisibility() {
        const entityType = Array.from(entityTypes).find(input => input.checked)?.value;
        const viewMode = Array.from(viewModes).find(input => input.checked)?.value;

        if (eventTimePeriod) {
          eventTimePeriod.style.display = (entityType === 'event' && viewMode === 'calendar') ? 'none' : '';
        }
      }

      entityTypes.forEach(input => input.addEventListener('change', updateVisibility));
      viewModes.forEach(input => input.addEventListener('change', updateVisibility));
      updateVisibility();
    },
  };
})(Drupal);
