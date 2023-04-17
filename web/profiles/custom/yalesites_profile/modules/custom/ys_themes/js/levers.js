((Drupal) => {
  Drupal.behaviors.ysLevers = {
    attach: function (context, settings) { // eslint-disable-line
      once("ysLevers", ".layout-container", context).forEach((element) => {
        function initStyleDrawer() {
          const ysThemeSettings = Object.keys(settings.ysThemes);
          for (let i = 0; i < ysThemeSettings.length; i++) {
            console.log(ysThemeSettings[i]);
            // const quoteStyle = context.querySelectorAll(
            //   "input[name='pull_quote_color']"
            // );
          }
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
