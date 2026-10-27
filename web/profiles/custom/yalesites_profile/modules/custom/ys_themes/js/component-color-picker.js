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
    selector.querySelectorAll('.palette-circle').forEach((circle) => {
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
    selector.classList.add('expanded');

    // Selects an option, by mouse or keyboard, and pushes the value to the
    // hidden select so the form saves it.
    const selectOption = (option) => {
      if (!option || option.getAttribute('data-selected') === 'true') {
        return;
      }

      const paletteValue = option.getAttribute('data-palette');
      if (
        !Array.from(selectElement.options).some(
          (opt) => opt.value === paletteValue,
        )
      ) {
        return;
      }

      markSelected(options, option);

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
    };

    container.addEventListener('click', (e) => {
      selectOption(e.target.closest('.palette-option'));
    });

    // The visible label points at the hidden select; send its clicks to the
    // swatch that holds the tab stop instead.
    if (selectElement.id && wrapper) {
      const label = wrapper.querySelector(`label[for="${selectElement.id}"]`);
      if (label) {
        label.addEventListener('click', (e) => {
          e.preventDefault();
          const tabStop = container.querySelector(
            '.palette-option[tabindex="0"]',
          );
          if (tabStop) {
            tabStop.focus();
          }
        });
      }
    }

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
