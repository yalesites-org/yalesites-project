((Drupal) => {
  Drupal.behaviors.ysCoreBlockForm = {
    attach: () => {
      // eslint-disable-line

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
        // Inputs and submit selectors are different on block content and layout builder forms.
        const inputSelector = blockContentForm
          ? blockType.inputSelector
          : blockType.lbInputSelector;
        const submitSelector = blockContentForm
          ? "edit-submit"
          : "edit-actions-submit";
        const submitButton = document.querySelector(
          `input[data-drupal-selector=${submitSelector}]`
        );

        if (submitButton) {
          // On click, check for custom errors.
          submitButton.addEventListener("click", () => {
            const input = document.querySelector(inputSelector);
            const errorMsg = getErrors(blockType);
            // If there are any errors, set custom validity on the chosen input field if it's visible.
            if (input.type === "hidden") {
              submitButton.setCustomValidity(errorMsg);
              // Otherwise set it on the submit button.
            } else {
              input.setCustomValidity(errorMsg);
            }
          });
        }
      }

      // Define block type objects with input to check plus min and max.
      const blockTypes = [
        {
          // Tabs
          itemSelector: "tr.paragraph-type--tab",
          inputSelector: 'input[data-drupal-selector^="edit-field-tabs"]',
          lbInputSelector:
            'input[data-drupal-selector^="edit-settings-block-form-field-tabs"]',
          min: 2,
          max: 5,
          type: "tabs",
        },
        {
          // Quick links
          itemSelector: "input.ui-autocomplete-input",
          inputSelector: 'input[data-drupal-selector^="edit-field-heading"]',
          lbInputSelector:
            'input[data-drupal-selector^="edit-settings-block-form-field-links"]',
          min: 3,
          max: 9,
          type: "links",
        },
        {
          // Media Grid
          itemSelector: ".paragraph-type--media-grid-item",
          inputSelector: 'input[data-drupal-selector^="edit-field-heading"]',
          lbInputSelector:
            'input[data-drupal-selector^="edit-settings-block-form-field-heading"]',
          min: 2,
          max: 0,
          type: "media grid items",
        },
        {
          // Gallery
          itemSelector: ".paragraph-type--gallery-item",
          inputSelector: 'input[data-drupal-selector^="edit-field-heading"]',
          lbInputSelector:
            'input[data-drupal-selector^="edit-settings-block-form-field-heading"]',
          min: 2,
          max: 0,
          type: "gallery items",
        },
      ];

      // Apply the function to each block type.
      blockTypes.forEach((blockType) => {
        // Check the form for this block type.
        if (document.querySelector(blockType.itemSelector)) {
          validateBlockType(blockType);
        }
      });
    },
  };
})(Drupal);
