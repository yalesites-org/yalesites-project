/**
 * @file
 * Inline "required" error for a checkbox group, shown before submit.
 *
 * Layout Builder Browser strips #ajax from the block form's submit, so a
 * server-side "required" error on a checkbox group reloads the form as a
 * standalone page and the editor leaves the dialog. A checkbox group cannot
 * carry HTML5 `required` either (it would demand every box), so this marks
 * the first box invalid while none is checked and lets the browser block the
 * submit. Its native bubble is suppressed in favor of the same inline error
 * markup Drupal's fieldset template prints for a server-side error, which
 * stays under the field until a box is checked.
 */
((Drupal, once) => {
  /**
   * Adds or removes one id in an element's aria-describedby list.
   *
   * @param {HTMLElement} element
   *   The element to update.
   * @param {string} id
   *   The id to add or remove.
   * @param {boolean} add
   *   Whether to add the id.
   */
  const toggleDescribedBy = (element, id, add) => {
    const ids = (element.getAttribute('aria-describedby') || '')
      .split(' ')
      .filter((token) => token && token !== id);
    if (add) {
      ids.push(id);
    }
    if (ids.length) {
      element.setAttribute('aria-describedby', ids.join(' '));
    } else {
      element.removeAttribute('aria-describedby');
    }
  };

  Drupal.behaviors.ysRequiredCheckboxes = {
    attach(context) {
      once(
        'ys-required-checkboxes',
        // Core copies a checkbox group's attributes onto each checkbox too, so
        // skip the inputs and take only the group's wrapper.
        '.js-ys-required-checkboxes:not(input)',
        context,
      ).forEach((group) => {
        const boxes = Array.from(
          group.querySelectorAll('input[type="checkbox"]'),
        );
        if (!boxes.length) {
          return;
        }
        const wrapper = group.querySelector('.fieldset__wrapper') || group;
        const label = group.querySelector('legend > span');

        // Reuse the server's message if the form was rendered with one (the
        // no-JavaScript fallback), so it is never shown twice.
        let error = group.querySelector('.fieldset__error-message');
        const serverError = !!error;
        if (!error) {
          error = document.createElement('div');
          error.textContent = group.dataset.ysRequiredMessage;
        }
        // Claro styles the fieldset class in the add modal, but the
        // off-canvas reset strips it in the configure tray; Gin Layout
        // Builder's error-message class keeps the text red in both.
        error.classList.add(
          'fieldset__error-message',
          'glb-form-item__error-message',
        );
        // The server may print the message as bare text; wrap it so the
        // inputs can point at it.
        let text = error.firstElementChild;
        if (!text) {
          text = document.createElement('span');
          text.textContent = error.textContent.trim();
          error.replaceChildren(text);
        }
        text.id = text.id || `${group.id}--error-message`;
        text.setAttribute('role', 'alert');

        const setInvalid = (invalid) => {
          label?.classList.toggle('has-error', invalid);
          [group, ...boxes].forEach((element) => {
            element.classList.toggle('error', invalid);
            if (invalid) {
              element.setAttribute('aria-invalid', 'true');
            } else {
              element.removeAttribute('aria-invalid');
            }
            toggleDescribedBy(element, text.id, invalid);
          });
          if (!invalid) {
            error.remove();
          } else if (!error.isConnected) {
            // Same place as the server markup: after the options, before
            // the description.
            wrapper.insertBefore(
              error,
              wrapper.querySelector(
                ':scope > [data-drupal-field-elements="description"]',
              ),
            );
          }
        };

        if (serverError) {
          setInvalid(true);
        }

        const sync = () => {
          const valid = boxes.some((box) => box.checked);
          boxes[0].setCustomValidity(valid ? '' : text.textContent);
          if (valid && error.isConnected) {
            setInvalid(false);
          }
        };
        boxes.forEach((box) => box.addEventListener('change', sync));
        sync();

        // The browser blocks the submit and fires this; cancelling it only
        // suppresses the native bubble, so show the inline error instead.
        boxes[0].addEventListener('invalid', (event) => {
          event.preventDefault();
          setInvalid(true);
          boxes[0].focus();
        });
      });
    },
  };
})(Drupal, once);
