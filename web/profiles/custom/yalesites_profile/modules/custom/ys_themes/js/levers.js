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
    },
  };
})(Drupal);
