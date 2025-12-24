/**
 * @file
 * Component Color Picker widget JavaScript.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Initialize component color picker widgets.
   */
  function initComponentColorPicker(selector) {
    const container = selector.querySelector('[data-palette-container]');
    const wrapper = selector.closest('.component-color-picker-wrapper');
    const options = selector.querySelectorAll('.palette-option');

    const allSelects = wrapper ? Array.from(wrapper.querySelectorAll('select')) : [];
    const selectWithName = allSelects.find(sel => sel.name && sel.name.length > 0);
    const hiddenSelect = allSelects.find(sel => sel.classList.contains('palette-select-hidden'));
    const selectElement = selectWithName || hiddenSelect || (allSelects.length > 0 ? allSelects[0] : null);

    if (!container || !selectElement) {
      return;
    }

    // Apply background colors from data-hex or data-color attributes.
    // Prefer hex values over CSS variables for better compatibility.
    const colorCircles = selector.querySelectorAll('.palette-circle');
    colorCircles.forEach(function(circle) {
      const hexValue = circle.getAttribute('data-hex');
      if (hexValue) {
        circle.style.setProperty('background-color', hexValue);
      }
      else {
        // Fallback to data-color if no hex is available.
        const colorValue = circle.getAttribute('data-color');
        if (colorValue && !colorValue.startsWith('var(')) {
          circle.style.setProperty('background-color', colorValue);
        }
      }
    });

    // Always expanded.
    selector.classList.add('expanded');

    // Handle palette option clicks.
    container.addEventListener('click', function (e) {
      const clickedOption = e.target.closest('.palette-option');

      if (!clickedOption) {
        return;
      }

      const paletteValue = clickedOption.getAttribute('data-palette');
      const isCurrentlySelected =
        clickedOption.getAttribute('data-selected') === 'true';

      if (isCurrentlySelected) {
        return;
      }

      options.forEach((opt) => opt.setAttribute('data-selected', 'false'));
      clickedOption.setAttribute('data-selected', 'true');

      const optionExists = Array.from(selectElement.options).some(
        (option) => option.value === paletteValue
      );

      if (!optionExists) {
        return;
      }

      if (selectElement.multiple) {
        Array.from(selectElement.options).forEach((option) => {
          option.selected = false;
        });
        const optionToSelect = Array.from(selectElement.options).find(
          (option) => option.value === paletteValue
        );
        if (optionToSelect) {
          optionToSelect.selected = true;
        }
      }
      else {
        selectElement.value = paletteValue;
      }

      selectElement.dispatchEvent(new Event('input', {
        bubbles: true,
        cancelable: true,
      }));
      selectElement.dispatchEvent(new Event('change', {
        bubbles: true,
        cancelable: true,
      }));

      const form = selectElement.closest('form');
      if (form) {
        form.dispatchEvent(new Event('change', {
          bubbles: true,
          cancelable: true,
        }));

        if (form.id && form.id.includes('layout-builder')) {
          const selectName = selectElement.name;
          if (selectName) {
            const allInputsWithSameName = form.querySelectorAll(
              `[name="${selectName}"]`
            );
            allInputsWithSameName.forEach((input) => {
              if (input !== selectElement && input.type === 'hidden') {
                input.value = paletteValue;
              }
            });
          }

          form.dispatchEvent(new Event('formUpdated', {
            bubbles: true,
            cancelable: true,
          }));

          const fieldWrapper = selectElement.closest(
            '.field--widget-component-color-picker, ' +
            '.js-form-item, .form-item'
          );
          if (fieldWrapper) {
            fieldWrapper.dispatchEvent(new Event('change', {
              bubbles: true,
              cancelable: true,
            }));
            fieldWrapper.dispatchEvent(new Event('formUpdated', {
              bubbles: true,
              cancelable: true,
            }));
          }
        }
      }
    });

    // Initialize selected state from the select element value.
    const currentValue = selectElement.value;
    if (currentValue) {
      options.forEach((opt) => {
        const paletteValue = opt.getAttribute('data-palette');
        if (paletteValue === currentValue) {
          opt.setAttribute('data-selected', 'true');
        } else {
          opt.setAttribute('data-selected', 'false');
        }
      });
    }
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

