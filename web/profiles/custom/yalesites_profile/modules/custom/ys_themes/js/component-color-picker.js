/**
 * @file
 * Component Color Picker widget JavaScript.
 */

(function (Drupal, once) {
  'use strict';

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
    return selects.find(sel => sel.name && sel.name.length > 0) ||
           selects.find(sel => sel.classList.contains('palette-select-hidden')) ||
           selects[0] ||
           null;
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
      }
      else {
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
      element.dispatchEvent(new Event(eventType, {
        bubbles: true,
        cancelable: true,
      }));
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
      '.field--widget-component-color-picker, .js-form-item, .form-item'
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
    }
    else {
      selectElement.value = paletteValue;
    }
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
    if (currentValue) {
      options.forEach((opt) => {
        opt.setAttribute('data-selected', opt.getAttribute('data-palette') === currentValue ? 'true' : 'false');
      });
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
    selector.classList.add('expanded');

    // Handle palette option clicks.
    container.addEventListener('click', (e) => {
      const clickedOption = e.target.closest('.palette-option');
      if (!clickedOption || clickedOption.getAttribute('data-selected') === 'true') {
        return;
      }

      const paletteValue = clickedOption.getAttribute('data-palette');
      if (!Array.from(selectElement.options).some((option) => option.value === paletteValue)) {
        return;
      }

      options.forEach((opt) => opt.setAttribute('data-selected', 'false'));
      clickedOption.setAttribute('data-selected', 'true');

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
    });

    initializeSelectedState(options, selectElement);
  }

  /**
   * Attach component color picker behavior.
   */
  if (typeof Drupal !== 'undefined' && Drupal.behaviors) {
    Drupal.behaviors.componentColorPicker = {
      attach: function (context, settings) {
        const selectors = once('component-color-picker', '[data-palette-selector]', context);
        selectors.forEach(initComponentColorPicker);
      },
    };
  }

})(Drupal, once);

