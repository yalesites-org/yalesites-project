/**
 * @file
 * Browser-side "required" check for a checkbox group.
 *
 * The browser enforces HTML5 `required` on a text field or select, which keeps
 * a validation error inside the Layout Builder dialog. A checkbox group cannot
 * carry `required` (it would demand every box), so its #required only fails
 * on the server, and with the dialog's non-Ajax submit that reloads the form
 * as a standalone page. This marks the group's first checkbox invalid while no
 * box is checked, so the browser blocks the submit, focuses the group and
 * shows and announces the message, the same as for any other required field.
 */
((Drupal, once) => {
  Drupal.behaviors.ysRequiredCheckboxes = {
    attach(context) {
      once(
        "ys-required-checkboxes",
        // Core copies a checkbox group's attributes onto each checkbox too, so
        // skip the inputs and take only the group's wrapper.
        ".js-ys-required-checkboxes:not(input)",
        context
      ).forEach((group) => {
        const boxes = Array.from(
          group.querySelectorAll('input[type="checkbox"]')
        );
        if (!boxes.length) {
          return;
        }
        const message = group.dataset.ysRequiredMessage;
        const sync = () => {
          const valid = boxes.some((box) => box.checked);
          boxes[0].setCustomValidity(valid ? "" : message);
        };
        boxes.forEach((box) => box.addEventListener("change", sync));
        sync();
      });
    },
  };
})(Drupal, once);
