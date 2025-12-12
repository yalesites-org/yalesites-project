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
    const hiddenSelectElement = wrapper
      ? wrapper.querySelector('select.palette-select-hidden')
      : null;
    const options = selector.querySelectorAll('.palette-option');
    let expandButton = selector.querySelector('[data-expand-button]');

    // If button is missing (Drupal might have stripped it), create it
    if (!expandButton && container) {
      expandButton = document.createElement('button');
      expandButton.type = 'button';
      expandButton.className = 'expand-indicator';
      expandButton.setAttribute('data-expand-button', '');
      expandButton.setAttribute('aria-label', 'Show all palettes');
      expandButton.innerHTML = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      container.insertBefore(expandButton, container.firstChild);
    }

    const allSelects = wrapper ? Array.from(wrapper.querySelectorAll('select')) : [];
    const selectWithName = allSelects.find(sel => sel.name && sel.name.length > 0);
    const hiddenSelect = allSelects.find(sel => sel.classList.contains('palette-select-hidden'));
    const selectElement = selectWithName || hiddenSelect || hiddenSelectElement || (allSelects.length > 0 ? allSelects[0] : null);

    if (!container || !selectElement || !expandButton) {
      return;
    }

    // Apply background colors from data-color attributes (to avoid Drupal sanitization of inline styles)
    const colorCircles = selector.querySelectorAll('.palette-circle[data-color]');
    colorCircles.forEach(function(circle) {
      const colorValue = circle.getAttribute('data-color');
      if (colorValue) {
        circle.style.setProperty('background-color', colorValue);
      }
    });

    // Always start expanded - no collapse functionality needed.
    selector.classList.add('expanded');
    let isExpanded = true;

    // Handle palette option clicks.
    container.addEventListener('click', function (e) {
      if (e.target.closest('[data-expand-button]')) {
        return;
      }

      const clickedOption = e.target.closest('.palette-option');

      if (clickedOption) {
        const paletteValue = clickedOption.getAttribute('data-palette');
        const isCurrentlySelected =
          clickedOption.getAttribute('data-selected') === 'true';

        if (isCurrentlySelected) {
          // Selected option clicked - no action needed since always expanded.
          return;
        }
        else {
          // Deselect all options.
          options.forEach((opt) => opt.setAttribute('data-selected', 'false'));

          // Select the clicked option.
          clickedOption.setAttribute('data-selected', 'true');

          // Update the hidden select element.
          const optionExists = Array.from(selectElement.options).some(
            (option) => option.value === paletteValue
          );

          if (!optionExists) {
            return;
          }

          // Use jQuery to set the value if available (Drupal's preferred method).
          if (typeof jQuery !== 'undefined') {
            const $select = jQuery(selectElement);
            $select.val(paletteValue);
          } else {
            // Fallback to native value setting.
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
            } else {
              selectElement.value = paletteValue;
            }
          }

          // Verify the value was set correctly.
          const actualValue = typeof jQuery !== 'undefined'
            ? jQuery(selectElement).val()
            : selectElement.value;

          if (actualValue !== paletteValue && !selectElement.multiple) {
            return;
          }

          // Trigger multiple events for Drupal form processing.
          if (typeof jQuery !== 'undefined') {
            const $select = jQuery(selectElement);
            $select.val(paletteValue);
            $select.triggerHandler('input');
            $select.trigger('change');

            const $parent = $select.parent();
            if ($parent.length) {
              $parent.trigger('change');
            }

            const $fieldItem = $select.closest('.js-form-item, .field--widget-component-color-picker');
            if ($fieldItem.length) {
              $fieldItem.trigger('change');
            }
          } else {
            const inputEvent = new Event('input', { bubbles: true, cancelable: true });
            selectElement.dispatchEvent(inputEvent);
            const changeEvent = new Event('change', { bubbles: true, cancelable: true });
            selectElement.dispatchEvent(changeEvent);
          }

          // Force form state update by triggering on the form if it exists.
          const form = selectElement.closest('form');
          if (form) {
            if (typeof jQuery !== 'undefined') {
              const $form = jQuery(form);
              $form.trigger('change');

              // For Layout Builder, trigger the formUpdated event
              if (form.id && form.id.includes('layout-builder')) {
                const selectName = selectElement.name;
                if (selectName) {
                  const allInputsWithSameName = form.querySelectorAll(`[name="${selectName}"]`);
                  allInputsWithSameName.forEach((input) => {
                    if (input !== selectElement && input.type === 'hidden') {
                      input.value = paletteValue;
                    }
                  });
                }

                $form.trigger('formUpdated');

                const fieldWrapper = selectElement.closest('.field--widget-component-color-picker, .js-form-item, .form-item');
                if (fieldWrapper) {
                  jQuery(fieldWrapper).trigger('change').trigger('formUpdated');
                }
              }
            }
          }

          // Options are always expanded, no collapse needed.
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

  // Fallback: Try to initialize on DOMContentLoaded if behaviors don't work
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      if (typeof once !== 'undefined') {
        const selectors = once('component-color-picker-fallback', '[data-palette-selector]', document);
        selectors.forEach(initComponentColorPicker);
      }
    });
  } else {
    if (typeof once !== 'undefined') {
      const selectors = once('component-color-picker-fallback', '[data-palette-selector]', document);
      selectors.forEach(initComponentColorPicker);
    }
  }
})(Drupal, once);

