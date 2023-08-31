((Drupal) => {
  Drupal.behaviors.ysCoreFooterSettings = {
    attach: function() { // eslint-disable-line
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
      const radioGroups = ['input[name="footer_variation"]'];

      // Apply the function to each radio input group
      radioGroups.forEach((group) => {
        handleRadioInputs(group);
      });
    },
  };
})(Drupal);
