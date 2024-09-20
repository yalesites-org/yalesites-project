((Drupal) => {
  Drupal.behaviors.ysCoreBlockForm = {
    attach: () => {
      // eslint-disable-line

      // Turns on console logs if true.
      const debug = false;

      function debugMsg(msg) {
        if (debug) {
          // eslint-disable-next-line no-console
          console.log(msg);
        }
      }

      /*
       * Function to validate that all media items contain media.
       *
       * @param object blockType
       *   blockType object.
       *
       * @return string
       *   The error message or empty string.
       * */
      function getErrors(blockType) {
        // Get all items of the specified type.
        const items = document.querySelectorAll(blockType.itemSelector);

        // Count the number of items present.
        const numberOfItems = items.length;
        if (
          numberOfItems < blockType.min ||
          (blockType.max > 0 && numberOfItems > blockType.max)
        ) {
          let messageText = "";
          if (blockType.max > 0) {
            messageText = `Number of ${blockType.type} must be between ${blockType.min} and ${blockType.max}. `;
          } else {
            messageText = `Number of ${blockType.type} must be ${blockType.min} or more. `;
          }
          return (
            messageText + `Number of ${blockType.type} added: ${numberOfItems}.`
          );
        }

        // An empty string signifies no errors and resets validation for the input.
        return "";
      }

      /*
       * Function to validate a block type.
       *
       * @param object blocktype
       *   Object containing block type information for validation.
       *
       * @return void
       * */
      function validateBlockType(blockType) {
        // Get the layout builder add and update forms.
        const blockContentForm = document.querySelector(
          'form[id^="block-content"]'
        );
        const submitSelector = blockContentForm
          ? "input[data-drupal-selector=edit-submit]"
          : "input[data-drupal-selector=edit-actions-submit]";
        const submitButton = document.querySelector(submitSelector);

        if (submitButton) {
          // If the data attribute of minMaxAdded does not exist on
          // submitButton, then add the event listener and add that attribute.
          if (!submitButton.hasAttribute("minMaxAdded")) {
            submitButton.setAttribute("minMaxAdded", "true");
            // On click, check for custom errors.
            submitButton.addEventListener("click", () => {
              // Since the selector can change as they enter correct data,
              // we must evaluate this inside the click event.
              const inputSelector =
                blockType.inputSelector.find((selector) => {
                  const input = document.querySelector(selector);
                  return input && input.type !== "hidden";
                }) || submitSelector;

              const input = document.querySelector(inputSelector);
              const errorMsg = getErrors(blockType);
              debugMsg("Setting errorMsg to:", input, errorMsg);
              input.setCustomValidity(errorMsg);
              /*
              This is a hack.  In order for the button to be used as a
              fallback, we must ensure that once they fix the issues, that
              The submit button no longer has custom validity set.  In most
              cases, the user will have fixed the issue, but it then would
              target another input than submit, causing its already set
              validity message to appear again.

              The issue with that is that once you set this, the form submits,
              so we must be very sure that it should happen by ensuring that
              the input is not the submit button and that it was just set to
              nothing, meaning no errors.

              If anyone finds a better way to do this, please fix this.
              */
              if (input !== submitButton && errorMsg === "") {
                submitButton.setCustomValidity("");
              }
            });
          }
        }
      }

      // Define block type objects with input to check plus min and max.
      const blockTypes = [
        {
          // Tabs
          itemSelector: "tr.paragraph-type--tab",
          inputSelector: [
            'input[data-drupal-selector^="edit-field-tabs"]',
            'input[data-drupal-selector^="edit-block-form-field-tabs"]',
            'input[data-drupal-selector^="edit-settings-block-form-field-tabs"]',
          ],
          min: 2,
          max: 5,
          type: "tabs",
        },
        {
          // Quick links
          itemSelector: "table[id^='field-links-values'] tr.draggable",
          inputSelector: [
            'input[data-drupal-selector^="edit-field-heading"]',
            'input[data-drupal-selector^="edit-settings-block-form-field-links"]',
          ],
          min: 3,
          max: 9,
          type: "links",
        },
        {
          // Media Grid
          itemSelector: ".paragraph-type--media-grid-item",
          inputSelector: [
            'input[data-drupal-selector^="edit-field-heading"]',
            'input[data-drupal-selector^="edit-settings-block-form-field-heading"]',
          ],
          min: 2,
          max: 0,
          type: "media grid items",
        },
        {
          // Gallery
          itemSelector: ".paragraph-type--gallery-item",
          inputSelector: [
            'input[data-drupal-selector^="edit-field-heading"]',
            'input[data-drupal-selector^="edit-settings-block-form-field-heading"]',
          ],
          min: 2,
          max: 0,
          type: "gallery items",
        },
      ];

      // Apply the function to each block type.
      blockTypes.forEach((blockType) => {
        // Check the form for this block type.
        debugMsg(`Trying to find: ${blockType.itemSelector}`);
        if (document.querySelector(blockType.itemSelector)) {
          debugMsg("Found the following blockType:", blockType);
          validateBlockType(blockType);
        }
      });
    },
  };
})(Drupal);
