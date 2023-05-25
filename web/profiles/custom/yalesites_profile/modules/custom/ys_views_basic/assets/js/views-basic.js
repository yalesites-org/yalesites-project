((Drupal) => {
  Drupal.behaviors.ysLevers = {
    attach: function () { // eslint-disable-line
    // Function to handle radio input checked behavior based on radio element selection.
      // Note: 'global_theme' is excluded because we're doing the same thing above,
      // but adding on to it. 
      function handleRadioInputs(radioGroup) {
        // Get references to the radio input elements within the specified group
        const radioInputs = document.querySelectorAll(radioGroup);
      
        // Add event listener to each radio input
        radioInputs.forEach(input => {
          input.addEventListener('change', function() {
            if (this.checked) {
              this.setAttribute('checked', 'checked');
              // Remove the 'checked' attribute from other radio inputs
              radioInputs.forEach(otherInput => {
                if (otherInput !== this) {
                  otherInput.removeAttribute('checked');
                }
              });
            }
          });
        });
      }
      
      // Store radio input groups in an array
      const radioGroups = [ 
        'input[name="settings[block_form][group_user_selection][entity_and_view_mode][entity_types]"]',
        'input[name="settings[block_form][group_user_selection][entity_and_view_mode][view_mode]"]'
      ];
      
      // Apply the function to each radio input group
      radioGroups.forEach(group => {
        handleRadioInputs(group);
      });
    },
  };
})(Drupal);