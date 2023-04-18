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

        function updateElement(selector, value) {
          const elements = document.querySelectorAll(selector);
          console.log(selector);
          for (let i = 0; i < elements.length; i++) {
            console.log(elements[i]);
            // const convertedAttributeName = selector
            //   .replace("data-", "")
            //   .replace(/-([a-z]?)/g, (m, g) => g.toUpperCase());

              //elements[i].dataset[convertedAttributeName] = value;
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

          // formItems.addEventListener("click", () => {

          // });

          //for (let i = 0; i < ysThemeSettings.length; i++) {
            //console.log(ysThemeSettings[i]);



            // const quoteStyle = context.querySelectorAll(
            //   "input[name='pull_quote_color']"
            // );
          //}
          // const quoteStyle = context.querySelectorAll(
          //   "input[name='pull_quote_color']"
          // );

          // const actionStyle = context.querySelectorAll(
          //   "input[name='action_color']"
          // );

          // for (let i = 0; i < quoteStyle.length; i++) {
          //   quoteStyle[i].addEventListener("click", (event) => {
          //     const quoteSelection = quoteStyle[i].value;
          //     document.documentElement.style.setProperty(
          //       "--color-theme-pull-quote-accent",
          //       `var(--color-${quoteSelection})`
          //     );
          //   });
          // }

          // for (let i = 0; i < actionStyle.length; i++) {
          //   actionStyle[i].addEventListener("click", (event) => {
          //     const actionSelection = actionStyle[i].value;
          //     document.documentElement.style.setProperty(
          //       "--color-theme-action",
          //       `var(--color-${actionSelection})`
          //     );
          //   });
          // }
        }

        setTimeout(() => {
          initStyleDrawer();
        }, 0);
      });
    },
  };
})(Drupal);
