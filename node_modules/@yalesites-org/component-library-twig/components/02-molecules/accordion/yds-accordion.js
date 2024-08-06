Drupal.behaviors.accordion = {
  attach(context) {
    // Selectors
    const items = context.querySelectorAll('.accordion-item');
    const controls = context.querySelectorAll('.accordion__controls');
    // Classes
    const itemContent = '.accordion-item__content';
    const itemState = 'data-accordion-expanded';
    const itemToggle = '.accordion-item__toggle';
    const accordion = '.accordion';
    const accordionControls = '.accordion__controls';
    const accordionToggleAll = '.accordion__toggle-all';
    const accordionItem = '.accordion-item';
    // States
    const buttonState = 'aria-expanded';
    const collapseText = 'Collapse';
    const expandText = 'Expand';

    // Function to expand an accordion item.
    const expand = (item) => {
      const toggle = item.querySelector(itemToggle);
      const content = item.querySelector(itemContent);

      content.style.setProperty(
        '--accordion-item-height',
        `${content.scrollHeight}px`,
      );
      item.setAttribute(itemState, 'true');
      toggle.setAttribute(buttonState, 'true');
    };

    // Function to collapse an accordion item.
    const collapse = (item) => {
      const toggle = item.querySelector(itemToggle);

      item.setAttribute(itemState, 'false');
      toggle.setAttribute(buttonState, 'false');
    };

    // Tests if a value starts with a set of text
    const alreadyStartsWith = (value, startsWith) => {
      return value.startsWith(startsWith);
    };

    // Replaces the first word of a string with another string
    const replaceFirstWord = (original, revised) => {
      // Capture the first word of a string
      const firstWord = /^[^\s]+/;

      return original.replace(firstWord, revised);
    };

    // Updates the button text and aria-exparnded state
    const updateToggleButtonToCollapse = (element) => {
      const button = element;

      if (alreadyStartsWith(button.innerHTML, collapseText)) return;

      button.innerHTML = replaceFirstWord(button.innerHTML, collapseText);

      // Set the button state so that arrows and actions take place
      button.setAttribute(buttonState, true);
    };

    // Update the toggle button to expand text
    const updateToggleButtonToExpand = (element) => {
      const button = element;

      if (alreadyStartsWith(button.innerHTML, expandText)) return;

      button.innerHTML = replaceFirstWord(button.innerHTML, expandText);

      // Set the button state so that arrows and actions take place
      button.setAttribute(buttonState, false);
    };

    // Returns the number of expanded items
    const expandedItemCount = (allItems) => {
      const itemsToEvaluate = allItems || [];

      return Array.from(itemsToEvaluate).filter((item) => {
        return item.getAttribute(itemState) === 'true';
      }).length;
    };

    // Tests if all of the items have been expanded
    const allItemsExpanded = (allItems) => {
      const unevalutedItems = allItems || [];

      return expandedItemCount(unevalutedItems) === unevalutedItems.length;
    };

    // Finds all items in a context
    const findAllItems = (domContext) => {
      return domContext.querySelectorAll(accordionItem);
    };

    // Finds the closest accordion from an item
    const findClosestAccordion = (item) => item.closest(accordion);
    // Finds the closest accordion controls from an item
    const findClosestAccordionControls = (item) =>
      findClosestAccordion(item).querySelector(accordionControls);
    // Finds the closest toggle button from an item
    const findClosestToggleButton = (item) =>
      findClosestAccordionControls(item).querySelector(accordionToggleAll);

    // Finds the toggler from an item
    const findTogglerFromItem = (item) => {
      return findClosestToggleButton(item);
    };

    // Tells if the expand/collapse button is expanded
    const isButtonExpanded = (element) => {
      const value = element.getAttribute(buttonState);

      return value === 'true';
    };

    // Tests if a list of items is empty
    const isEmpty = (itemsList) => {
      return itemsList.length === 0;
    };

    // Tests if an item is expanded
    const isExpanded = (item) => item.getAttribute(buttonState) === 'true';

    // Given a list of items, run a state function on each item
    // Currently, it's recommended to use one of the following:
    //   collapse
    //   expand
    const setAllItemStates = (allItems, stateFn) => {
      allItems.forEach((item) => stateFn(item));
    };

    // Toggles an item's state to match the toggler
    const toggleItemState = (toggler, item) =>
      isExpanded(toggler) ? collapse(item) : expand(item);

    // Check if all of the items have been expanded or collapsed
    const updateToggleButtonState = (allItems) => {
      if (isEmpty(allItems)) return;

      const toggler = findTogglerFromItem(allItems[0]);

      if (allItemsExpanded(allItems)) {
        updateToggleButtonToCollapse(toggler);
      } else {
        updateToggleButtonToExpand(toggler);
      }
    };

    // Graceful degrading by only collapsing if JavaScript is enabled
    const collapseAllItems = (allItems) => {
      allItems.forEach((item) => {
        collapse(item);
      });
    };

    // Attaches item click events to subitems
    const attachItemClickEvent = (allItems) => {
      // Toggle accordion content when toggle is activated.
      allItems.forEach((item) => {
        const toggle = item.querySelector(itemToggle);
        const otherAccordionItems =
          findClosestAccordion(item).querySelectorAll(accordionItem);

        toggle.addEventListener('click', () => {
          toggleItemState(toggle, item);
          updateToggleButtonState(otherAccordionItems);
        });
      });
    };

    // Determines if there is only one item
    const hasMoreThanOneItem = (allItems) => {
      return allItems.length > 1;
    };

    // Hides the toggle button if there is one item
    const hideToggleIfOneItem = (parentUl, allItems) => {
      if (hasMoreThanOneItem(allItems)) return;

      const ul = parentUl;
      ul.style.display = 'none';
    };

    // Display the toggle button
    const showToggleButton = (ul) => {
      const control = ul;
      control.style.display = '';
    };

    // Show accordion controls if JavaScript is enabled
    const hideOrShowToggleButtons = (ul, allItems) => {
      if (hasMoreThanOneItem(allItems)) {
        showToggleButton(ul);
      } else {
        hideToggleIfOneItem(ul, allItems);
      }
    };

    // Traverses each control to hide toggles with one item
    const hideSingleItemToggles = (allControls) => {
      allControls.forEach((parentUl) => {
        const allItems = findAllItems(parentUl.parentNode);
        hideOrShowToggleButtons(parentUl, allItems);
      });
    };

    // Attaches the toggle button click event to controls
    const attachToggleButtonClickEvent = (allControls) => {
      allControls.forEach((parentUl) => {
        // Get all items relevant to the control.
        const allItems = findAllItems(parentUl.parentNode);
        // Add click listener on the parent <ul>
        parentUl.addEventListener('click', (e) => {
          if (isButtonExpanded(e.target)) {
            updateToggleButtonToExpand(e.target);
            setAllItemStates(allItems, collapse);
          } else {
            updateToggleButtonToCollapse(e.target);
            setAllItemStates(allItems, expand);
          }
        });
      });
    };

    collapseAllItems(items);
    hideSingleItemToggles(controls);
    attachItemClickEvent(items);
    attachToggleButtonClickEvent(controls);
  },
};
