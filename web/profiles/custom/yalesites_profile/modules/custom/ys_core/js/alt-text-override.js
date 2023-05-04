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

        // Hide alt text if decorative is checked
        const altTextOverrides = context.querySelectorAll('.field--type-alt-text-override');

        for (let i = 0; i < altTextOverrides.length; i++) {
          const decorative = altTextOverrides[i].querySelector('.ys-core--alt-override--decorative');
          const altTextOverrideField = altTextOverrides[i].querySelector('.form-type--textfield');

          // Initial hide if decorative is checked.
          if (decorative.checked) {
            altTextOverrideField.style.display = 'none';
          }

          // Event listener on click to hide/show on decorative check.
          decorative.addEventListener('click', (e) => {
            if (e.target.checked) {
              altTextOverrideField.style.display = 'none';
            }
            else {
              altTextOverrideField.style.display = 'block';
            }
          });
        }

        // Override placeholder alt text for inline blocks.
        waitForElm('.field--name-field-media img').then((elm) => {
          const overrideAltText = context.querySelectorAll('.ys-core--alt-override--alt-text');
          overrideAltText[0].placeholder = elm.alt;
        });

        // Override placeholder alt text for paragraph existing images.
        waitForElm('.paragraphs-subform').then((elm) => {
          const subParagraphs = context.querySelectorAll('.paragraphs-subform');
          for (let i = 0; i < subParagraphs.length; i++) {
            const selectedImage = subParagraphs[i].querySelector('.field--name-field-image img');

            if(selectedImage) {
              const altTextField = subParagraphs[i].querySelector('.ys-core--alt-override--alt-text');
              altTextField.placeholder = selectedImage.alt;
            }

          }
        });

        // Override placeholder alt text for paragraph new images.
        waitForElm('.field--name-field-image img').then((elm) => {
          const overrideAltText = context.querySelectorAll('.ys-core--alt-override--alt-text');
          overrideAltText[0].placeholder = elm.alt;
        });

      });
    },
  };
})(Drupal);
