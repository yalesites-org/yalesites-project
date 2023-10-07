((Drupal) => {
  Drupal.behaviors.ysCoreHeaderFooterSettings = {
    attach: function() { // eslint-disable-line
      // Function to handle radio input checked behavior based on radio element selection.
      function handleRadioInputs(radioGroup) {
        // Get references to the radio input elements within the specified group
        const radioInputs = document.querySelectorAll(radioGroup);
        const detailGroups = document.querySelectorAll(
          ".ys-core-footer-settings-form details"
        );

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

              // Closes all details after selecting a new footer variation.
              for (let i = 0; i < detailGroups.length; i++) {
                detailGroups[i].removeAttribute("open");
              }
            }
          });
        });
      }

      // Store radio input groups in an array
      const headerRadioGroups = ['input[name="header_variation"]'];
      const footerRadioGroups = ['input[name="footer_variation"]'];

      // Apply the function to each radio input group
      headerRadioGroups.forEach((group) => {
        handleRadioInputs(group);
      });

      // Apply the function to each radio input group
      footerRadioGroups.forEach((group) => {
        handleRadioInputs(group);
      });
    },
  };
})(Drupal);
