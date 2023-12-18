((Drupal) => {
  Drupal.behaviors.ysCoreBlockForm = {
    attach: () => { // eslint-disable-line

      function errorMessage(blockType, numberOfItems) {
        let messageText = '';
        if (blockType.max > 0) {
          messageText = `Number of ${blockType.type} must be between ${blockType.min} and ${blockType.max}. `;
        }
        else {
          messageText = `Number of ${blockType.type} must be above ${blockType.min}. `;
        }
        return messageText + `Number of ${blockType.type} added: ${numberOfItems}.`;
      }

      // Function to handle block types and check for maximum/minimum number of inputs.
      function handleBlockTypes(blockType) {
        // Get forms that start with layout-builder (the add and update forms)
        const blockForm = document.querySelector('form[id^="layout-builder"]');
        if (blockForm) {

          // Get all of the input elements of the specified type.
          const items = document.querySelectorAll(blockType.itemSelector);
          const input = document.querySelector(blockType.inputSelector);
          // Count the number of items present.
          const numberOfItems = items.length;
          // If we are below the min or above the max, trigger an error.
          if (numberOfItems < blockType.min || (blockType.max > 0 && numberOfItems > blockType.max)) {

            let itemsContainMedia = true;
            items.forEach((item) => {
              console.log(item);
              console.log(item.querySelector('.summary-content'));
              console.log(item.querySelector('img'));
              if (!item.querySelector('.summary-content') && !item.querySelector('img')) {
                itemsContainMedia = false;
              }
            });

            const errorString = !itemsContainMedia ? 'All items must contain media.' : errorMessage(blockType, numberOfItems);

            if (input) {
              input.setCustomValidity(errorString);
              blockForm.addEventListener("submit", (event) => {
                input.reportValidity();
                event.preventDefault();
              });
            }
          }
        }
      }

      // Define block type objects with input to check plus min and max.
      const blockTypes = [
        {
          // Tabs
          itemSelector: '[data-drupal-selector^="edit-settings-block-form-field-tabs"].paragraph-top',
          inputSelector: 'input[data-drupal-selector^="edit-settings-block-form-field-tabs"]',
          min: 2,
          max: 5,
          type: 'tabs',
        },
        {
          // Quick links
          itemSelector: '[data-drupal-selector^="edit-settings-block-form-field-links"].ui-autocomplete-input',
          inputSelector: 'input[data-drupal-selector^="edit-settings-block-form-field-links"]',
          min: 3,
          max: 9,
          type: 'links',
        },
        {
          // Media Grid
          itemSelector: '[data-drupal-selector^="edit-settings-block-form-field-media-grid-items"].paragraph-top',
          inputSelector: 'input[data-drupal-selector^="edit-settings-block-form-field-heading"]',
          min: 2,
          max: 0,
          type: 'media grid items',
        },
        {
          // Gallery
          itemSelector: '[data-drupal-selector^="edit-settings-block-form-field-links"].ui-autocomplete-input',
          inputSelector: 'input[data-drupal-selector^="edit-settings-block-form-field-links"]',
          min: 2,
          max: 0,
          type: 'gallery items',
        }
      ];

      // Apply the function to each block type.
      blockTypes.forEach((blockType) => {
        handleBlockTypes(blockType);
      });

    },
  };
})(Drupal);
