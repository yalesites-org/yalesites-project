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
      // Add Checked attribute to clicked attibute to visually identify the
      // active clicked theme option.

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

          // Read active global theme and find the global-theme number and
          // update it when on input change.

          // apply global theme value to our components 
          const targetElements = document.querySelectorAll('span.component-color');

          // Get the original background style value
          targetElements.forEach(targetElement => {
            const globalTheme = document.querySelector('div[data-global-theme]');
            const currentTheme = globalTheme.getAttribute('data-global-theme');
            const originalBackground = targetElement.style.background;

            // Construct the regular expression pattern dynamically
            const regexPattern = new RegExp(`--global-themes-(\\w+)-`);

            // // Replace the --global-themes-${globalTheme}- portion
            // const updatedBackground = originalBackground.replace(/--global-themes-(one|two|three|four|five)-/, `--global-themes-${currentTheme}-`);

            // Replace the --global-themes-${globalTheme}- portion
            const updatedBackground = originalBackground.replace(regexPattern, `--global-themes-${currentTheme}-`);

            // Update the inline style with the modified background value
            targetElement.style.background = updatedBackground;
          });
        });
      });
    },
  };
})(Drupal);
