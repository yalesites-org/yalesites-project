/**
 * @file
 * Component Color Picker widget JavaScript.
 */

(function (Drupal, once) {
  /**
   * Finds the select element for the color picker.
   *
   * @param {HTMLElement} wrapper
   *   The wrapper element.
   *
   * @return {HTMLElement|null}
   *   The select element or null.
   */
  function findSelectElement(wrapper) {
    if (!wrapper) {
      return null;
    }
    const selects = Array.from(wrapper.querySelectorAll('select'));
    return (
      selects.find((sel) => sel.name && sel.name.length > 0) ||
      selects.find((sel) => sel.classList.contains('palette-select-hidden')) ||
      selects[0] ||
      null
    );
  }

  /**
   * Applies background colors to palette circles.
   *
   * @param {HTMLElement} selector
   *   The selector element.
   */
  function applyCircleColors(selector) {
    selector
      .querySelectorAll('.palette-circle, .palette-dropdown-swatch')
      .forEach((circle) => {
        const hexValue = circle.getAttribute('data-hex');
        if (hexValue) {
          circle.style.setProperty('background-color', hexValue);
        } else {
          const colorValue = circle.getAttribute('data-color');
          if (colorValue && !colorValue.startsWith('var(')) {
            circle.style.setProperty('background-color', colorValue);
          }
        }
      });
  }

  /**
   * Dispatches an event on an element.
   *
   * @param {HTMLElement} element
   *   The element to dispatch the event on.
   * @param {string} eventType
   *   The event type.
   */
  function dispatchEvent(element, eventType) {
    if (element) {
      element.dispatchEvent(
        new Event(eventType, {
          bubbles: true,
          cancelable: true,
        }),
      );
    }
  }

  /**
   * Handles Layout Builder form updates.
   *
   * @param {HTMLElement} form
   *   The form element.
   * @param {HTMLElement} selectElement
   *   The select element.
   * @param {string} paletteValue
   *   The selected palette value.
   */
  function handleLayoutBuilderForm(form, selectElement, paletteValue) {
    const selectName = selectElement.name;
    if (selectName) {
      form.querySelectorAll(`[name="${selectName}"]`).forEach((input) => {
        if (input !== selectElement && input.type === 'hidden') {
          input.value = paletteValue;
        }
      });
    }

    dispatchEvent(form, 'formUpdated');

    const fieldWrapper = selectElement.closest(
      '.field--widget-component-color-picker, .js-form-item, .form-item',
    );
    if (fieldWrapper) {
      dispatchEvent(fieldWrapper, 'change');
      dispatchEvent(fieldWrapper, 'formUpdated');
    }
  }

  /**
   * Updates the select element value.
   *
   * @param {HTMLElement} selectElement
   *   The select element.
   * @param {string} paletteValue
   *   The palette value to select.
   */
  function updateSelectValue(selectElement, paletteValue) {
    if (selectElement.multiple) {
      Array.from(selectElement.options).forEach((option) => {
        option.selected = option.value === paletteValue;
      });
    } else {
      selectElement.value = paletteValue;
    }
  }

  /**
   * Checks that the select element offers a value.
   *
   * @param {HTMLElement} selectElement
   *   The select element.
   * @param {string} paletteValue
   *   The palette value.
   *
   * @return {boolean}
   *   TRUE when the select has an option with that value.
   */
  function selectHasValue(selectElement, paletteValue) {
    return Array.from(selectElement.options).some(
      (opt) => opt.value === paletteValue,
    );
  }

  /**
   * Pushes a chosen value to the hidden select so the form saves it.
   *
   * @param {HTMLElement} selectElement
   *   The select element.
   * @param {string} paletteValue
   *   The palette value to select.
   */
  function commitSelection(selectElement, paletteValue) {
    updateSelectValue(selectElement, paletteValue);
    dispatchEvent(selectElement, 'input');
    dispatchEvent(selectElement, 'change');

    const form = selectElement.closest('form');
    if (form) {
      dispatchEvent(form, 'change');
      if (form.id && form.id.includes('layout-builder')) {
        handleLayoutBuilderForm(form, selectElement, paletteValue);
      }
    }
  }

  /**
   * Sends clicks on the visible field label to the picker's focusable control.
   *
   * The label points at the hidden select, which is out of the tab order.
   *
   * @param {HTMLElement} wrapper
   *   The picker wrapper.
   * @param {HTMLElement} selectElement
   *   The select element.
   * @param {function} getTarget
   *   Returns the element to focus.
   */
  function redirectLabelClicks(wrapper, selectElement, getTarget) {
    if (!selectElement.id || !wrapper) {
      return;
    }
    const label = wrapper.querySelector(`label[for="${selectElement.id}"]`);
    if (label) {
      label.addEventListener('click', (e) => {
        e.preventDefault();
        const target = getTarget();
        if (target) {
          target.focus();
        }
      });
    }
  }

  /**
   * Makes one palette option the group's only tab stop.
   *
   * @param {NodeList} options
   *   The palette options.
   * @param {HTMLElement} target
   *   The option that takes the tab stop.
   */
  function setTabStop(options, target) {
    options.forEach((opt) =>
      opt.setAttribute('tabindex', opt === target ? '0' : '-1'),
    );
  }

  /**
   * Marks one palette option as selected, and gives it the tab stop.
   *
   * @param {NodeList} options
   *   The palette options.
   * @param {HTMLElement|undefined} selectedOption
   *   The option to mark selected, or undefined to leave none selected.
   */
  function markSelected(options, selectedOption) {
    options.forEach((opt) => {
      const isSelected = opt === selectedOption ? 'true' : 'false';
      opt.setAttribute('data-selected', isSelected);
      opt.setAttribute('aria-checked', isSelected);
    });
    // With nothing selected the group still needs one tab stop.
    setTabStop(options, selectedOption || options[0]);
  }

  /**
   * Initializes the selected state from the select element.
   *
   * @param {NodeList} options
   *   The palette options.
   * @param {HTMLElement} selectElement
   *   The select element.
   */
  function initializeSelectedState(options, selectElement) {
    const currentValue = selectElement.value;
    // An empty value (e.g. a multiple select) keeps the server-rendered state.
    if (!currentValue) {
      return;
    }
    markSelected(
      options,
      Array.from(options).find(
        (opt) => opt.getAttribute('data-palette') === currentValue,
      ),
    );
  }

  /**
   * Counts dropdown pickers, so each gets unique element ids.
   */
  let dropdownCount = 0;

  /**
   * Scrolls a list just enough to show one of its options.
   *
   * Only the list scrolls: scrollIntoView() on the option would also scroll
   * the settings panel around it.
   *
   * @param {HTMLElement} list
   *   The scrolling list (the options' offsetParent).
   * @param {HTMLElement} option
   *   The option to show.
   */
  function keepOptionInView(list, option) {
    // Keep the list's padding in view too: the active option's outline is
    // drawn in it.
    const padding = parseFloat(getComputedStyle(list).paddingTop) || 0;
    const top = option.offsetTop - padding;
    const bottom = option.offsetTop + option.offsetHeight + padding;
    if (top < list.scrollTop) {
      list.scrollTo(0, top);
    } else if (bottom > list.scrollTop + list.clientHeight) {
      list.scrollTo(0, bottom - list.clientHeight);
    }
  }

  /**
   * Initializes a dropdown picker: the APG select-only combobox pattern.
   *
   * Focus stays on the combobox; aria-activedescendant names the option the
   * arrow keys have moved to, and Enter or Space selects it.
   *
   * @param {HTMLElement} selector
   *   The picker element.
   * @param {HTMLElement} list
   *   The listbox.
   * @param {HTMLElement} wrapper
   *   The picker wrapper.
   * @param {HTMLElement} selectElement
   *   The hidden select that carries the submitted value.
   */
  function initDropdownPicker(selector, list, wrapper, selectElement) {
    const trigger = selector.querySelector('[data-palette-trigger]');
    const valueDisplay = selector.querySelector('[data-palette-value]');
    const options = Array.from(list.querySelectorAll('[role="option"]'));
    if (!trigger || !options.length) {
      return;
    }

    dropdownCount += 1;
    const baseId = `${selectElement.id || 'palette'}-dropdown-${dropdownCount}`;
    list.setAttribute('id', `${baseId}-listbox`);
    trigger.setAttribute('aria-controls', `${baseId}-listbox`);
    options.forEach((option, index) => {
      option.setAttribute('id', `${baseId}-option-${index}`);
    });

    let activeOption = null;

    function isOpen() {
      return trigger.getAttribute('aria-expanded') === 'true';
    }

    function setActive(option) {
      activeOption = option || null;
      options.forEach((opt) =>
        opt.classList.toggle('is-active', opt === activeOption),
      );
      if (activeOption) {
        trigger.setAttribute('aria-activedescendant', activeOption.id);
        keepOptionInView(list, activeOption);
      } else {
        trigger.removeAttribute('aria-activedescendant');
      }
    }

    // Shows an option as the selected one, in the list and on the combobox.
    function showSelected(option) {
      options.forEach((opt) => {
        const isSelected = opt === option ? 'true' : 'false';
        opt.setAttribute('aria-selected', isSelected);
        opt.setAttribute('data-selected', isSelected);
      });
      if (valueDisplay) {
        valueDisplay.replaceChildren(
          ...Array.from(
            option.querySelectorAll(
              '.palette-dropdown-swatch, .palette-dropdown-text',
            ),
          ).map((node) => node.cloneNode(true)),
        );
      }
    }

    function close() {
      // eslint-disable-next-line no-use-before-define
      document.removeEventListener('pointerdown', onOutsidePointer);
      if (isOpen()) {
        trigger.setAttribute('aria-expanded', 'false');
        setActive(null);
      }
    }

    function onOutsidePointer(e) {
      if (!selector.contains(e.target)) {
        close();
      }
    }

    function open(option) {
      if (!isOpen()) {
        trigger.setAttribute('aria-expanded', 'true');
        document.addEventListener('pointerdown', onOutsidePointer);
        // Bring the open list into view vertically. The widget never overflows
        // its container sideways, so 'nearest' never scrolls the panel
        // horizontally.
        list.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      }
      setActive(
        option ||
          options.find((opt) => opt.getAttribute('aria-selected') === 'true') ||
          options[0],
      );
    }

    // Makes an option the selection, unless it already is, and pushes the
    // value to the hidden select so the form saves it.
    function commitOption(option) {
      if (!option || option.getAttribute('aria-selected') === 'true') {
        return;
      }
      const paletteValue = option.getAttribute('data-palette');
      if (!selectHasValue(selectElement, paletteValue)) {
        return;
      }
      showSelected(option);
      commitSelection(selectElement, paletteValue);
    }

    // Selects an option, closes the list, and returns focus to the combobox.
    function choose(option) {
      close();
      trigger.focus();
      commitOption(option);
    }

    trigger.addEventListener('click', () => {
      if (isOpen()) {
        close();
      } else {
        open();
      }
    });

    // Keep focus on the combobox while the mouse is on the list.
    list.addEventListener('mousedown', (e) => e.preventDefault());
    list.addEventListener('click', (e) => {
      const option = e.target.closest('[role="option"]');
      if (option && list.contains(option)) {
        choose(option);
      }
    });

    // Tab, or any other way focus leaves, closes the list.
    trigger.addEventListener('focusout', (e) => {
      if (!selector.contains(e.relatedTarget)) {
        close();
      }
    });

    trigger.addEventListener('keydown', (e) => {
      const last = options.length - 1;

      if (!isOpen()) {
        switch (e.key) {
          case 'ArrowDown':
          case 'ArrowUp':
          case 'Enter':
          case ' ':
            open();
            break;

          case 'Home':
            open(options[0]);
            break;

          case 'End':
            open(options[last]);
            break;

          default:
            return;
        }
        // Enter would otherwise submit the dialog form.
        e.preventDefault();
        return;
      }

      const index = options.indexOf(activeOption);
      switch (e.key) {
        case 'ArrowDown':
          setActive(options[Math.min(index + 1, last)]);
          break;

        case 'ArrowUp':
          setActive(options[Math.max(index - 1, 0)]);
          break;

        case 'Home':
          setActive(options[0]);
          break;

        case 'End':
          setActive(options[last]);
          break;

        case 'Enter':
        case ' ':
          choose(activeOption);
          break;

        case 'Escape':
          // Close the list, not the dialog around it.
          e.stopPropagation();
          close();
          break;

        case 'Tab': {
          // Per the APG select-only combobox, Tab keeps the highlighted
          // option, then focus moves on as usual.
          const highlighted = activeOption;
          close();
          commitOption(highlighted);
          return;
        }

        default:
          return;
      }
      e.preventDefault();
    });

    redirectLabelClicks(wrapper, selectElement, () => trigger);

    // An empty value (e.g. a multiple select) keeps the server-rendered state.
    const current = options.find(
      (opt) =>
        selectElement.value &&
        opt.getAttribute('data-palette') === selectElement.value,
    );
    if (current) {
      showSelected(current);
    }
  }

  /**
   * Initialize component color picker widgets.
   */
  function initComponentColorPicker(selector) {
    const container = selector.querySelector('[data-palette-container]');
    const wrapper = selector.closest('.component-color-picker-wrapper');
    const options = selector.querySelectorAll('.palette-option');
    const selectElement = findSelectElement(wrapper);

    if (!container || !selectElement) {
      return;
    }

    applyCircleColors(selector);

    if (selector.getAttribute('data-palette-display') === 'dropdown') {
      initDropdownPicker(selector, container, wrapper, selectElement);
      return;
    }

    selector.classList.add('expanded');

    // Selects an option, by mouse or keyboard, and pushes the value to the
    // hidden select so the form saves it.
    const selectOption = (option) => {
      if (!option || option.getAttribute('data-selected') === 'true') {
        return;
      }

      const paletteValue = option.getAttribute('data-palette');
      if (!selectHasValue(selectElement, paletteValue)) {
        return;
      }

      markSelected(options, option);
      commitSelection(selectElement, paletteValue);
    };

    container.addEventListener('click', (e) => {
      selectOption(e.target.closest('.palette-option'));
    });

    // The swatch that holds the tab stop takes the label's clicks.
    redirectLabelClicks(wrapper, selectElement, () =>
      container.querySelector('.palette-option[tabindex="0"]'),
    );

    // Arrow keys move between swatches; Enter or Space selects one.
    container.addEventListener('keydown', (e) => {
      const current = e.target.closest('.palette-option');
      if (!current) {
        return;
      }
      const list = Array.from(options);
      const index = list.indexOf(current);
      let next = null;

      switch (e.key) {
        case 'ArrowRight':
        case 'ArrowDown':
          next = list[(index + 1) % list.length];
          break;

        case 'ArrowLeft':
        case 'ArrowUp':
          next = list[(index - 1 + list.length) % list.length];
          break;

        case 'Home':
          [next] = list;
          break;

        case 'End':
          next = list[list.length - 1];
          break;

        case 'Enter':
        case ' ':
          // Enter would otherwise submit the dialog form.
          e.preventDefault();
          selectOption(current);
          return;

        default:
          return;
      }

      e.preventDefault();
      setTabStop(options, next);
      next.focus();
    });

    initializeSelectedState(options, selectElement);
  }

  /**
   * Attach component color picker behavior.
   */
  if (typeof Drupal !== 'undefined' && Drupal.behaviors) {
    Drupal.behaviors.componentColorPicker = {
      attach(context, settings) {
        const selectors = once(
          'component-color-picker',
          '[data-palette-selector]',
          context,
        );
        selectors.forEach(initComponentColorPicker);
      },
    };
  }
})(Drupal, once);
