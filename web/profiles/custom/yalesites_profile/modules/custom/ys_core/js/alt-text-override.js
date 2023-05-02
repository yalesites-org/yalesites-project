((Drupal) => {
  Drupal.behaviors.ysCoreAltTextOverride = {
    attach: function (context, settings) { // eslint-disable-line
      once("ysCoreAltTextOverride", ".ys-core--alt-text-override", context).forEach((element) => {

        function waitForElm(selector) {
          return new Promise(resolve => {
            if (document.querySelector(selector)) {
              return resolve(document.querySelector(selector));
            }

            const observer = new MutationObserver(mutations => {
              if (document.querySelector(selector)) {
                resolve(document.querySelector(selector));
                  observer.disconnect();
              }
            });

            observer.observe(document.body, {
              childList: true,
              subtree: true
            });
          });
        }

        waitForElm('.field--name-field-media img').then((elm) => {
          const overrideAltText = context.querySelectorAll('.ys-core--alt-override--alt-text');
          overrideAltText[0].placeholder = elm.alt;

        });

      });
    },
  };
})(Drupal);
