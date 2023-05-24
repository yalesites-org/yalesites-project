((Drupal) => {
  Drupal.behaviors.ysLevers = {
    attach: function (context, settings) { // eslint-disable-line
      once("ysLevers", ".layout-container", context).forEach((element) => {
        // Update root CSS variables
        function updateRoot(selector, value) {
          const prefix = selector.match(/--([a-z]*[0-9]*)/g);
          document.documentElement.style.setProperty(
            selector,
            `var(${prefix[0]}-${value})`
          );
        }

        // Update data attributes
        function updateElement(selector, value) {
          const elements = document.querySelectorAll(selector);
          // These convert from element[data-attribute-name] to attributeName
          const dataAttribute = selector.match(/\[(.*?)\]/);
          const convertedAttributeName = dataAttribute[1]
            .replace("data-", "")
            .replace(/-([a-z]?)/g, (m, g) => g.toUpperCase());
          for (let i = 0; i < elements.length; i++) {
            elements[i].dataset[convertedAttributeName] = value;
          }
        }

        function initStyleDrawer() {
          const formItems = context.querySelectorAll(
            "input.ys-themes--setting"
          );

          for (let i = 0; i < formItems.length; i++) {
            formItems[i].addEventListener("click", (event) => {
              const { propType, selector } = event.target.dataset;
              if (propType === "root") {
                updateRoot(selector, formItems[i].value);
              }
              if (propType === "element") {
                updateElement(selector, formItems[i].value);
              }
            });
          }
        }

        // Wait for the drawer items to become available before init.
        setTimeout(() => {
          initStyleDrawer();
        }, 0);
      });

      //
      // Add Checked (checked = "checked") attribute to clicked radio elements 
      // to visually identify the active clicked theme option.
      //

      // Get all radio inputs in the glogal_theme name group
      const radioInputs = document.querySelectorAll('input[name="global_theme"]');

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

          // Get each component-color span element
          const targetElements = document.querySelectorAll('span.component-color');

          // Read active global theme and capture the number in a variable and
          // update it on radio input paletter selection change.
          targetElements.forEach(targetElement => {
            const globalTheme = document.querySelector('div[data-global-theme]');
            const currentTheme = globalTheme.getAttribute('data-global-theme');
            const originalBackground = targetElement.style.background;

            // Construct the regular expression pattern dynamically
            const regexPattern = new RegExp(`--global-themes-(\\w+)-`);

            // Replace the --global-themes-${globalTheme}- portion
            const updatedBackground = originalBackground.replace(regexPattern, `--global-themes-${currentTheme}-`);

            // Update the inline style with the modified background value
            targetElement.style.background = updatedBackground;
          });
        });
      });

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
        'input[name="nav_position"]',
        'input[name="nav_type"]',
        'input[name="button_theme"]',
        'input[name="header_theme"]',
        'input[name="footer_theme"]'
      ];
      
      // Apply the function to each radio input group
      radioGroups.forEach(group => {
        handleRadioInputs(group);
      });
    },
  };
})(Drupal);
