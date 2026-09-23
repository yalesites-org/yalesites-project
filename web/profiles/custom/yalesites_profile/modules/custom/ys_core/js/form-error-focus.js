((Drupal, once) => {
  const FOCUSABLE =
    'input:not([type="hidden"]), select, textarea, button, a[href], [contenteditable="true"]';

  const canFocus = (el) =>
    el.matches(FOCUSABLE) &&
    !el.disabled &&
    el.getAttribute("tabindex") !== "-1" &&
    el.getClientRects().length > 0;

  /**
   * Returns the visible control to focus for an invalid element.
   *
   * The invalid element can be a group (the fieldset of radios, checkboxes,
   * or a media picker) or hidden behind a widget (a Chosen select). Then the
   * checked option, or else the first form control, in its group wins.
   *
   * @param {HTMLElement} invalid
   *   The first element marked aria-invalid.
   *
   * @return {HTMLElement|undefined}
   *   The control to focus, if the group has one.
   */
  const focusTarget = (invalid) => {
    if (canFocus(invalid)) {
      return invalid;
    }
    const group = invalid.closest("fieldset, .js-form-item") || invalid;
    const controls = [...group.querySelectorAll(FOCUSABLE)].filter(canFocus);
    // Skip buttons (Gin's help toggle) when a form control is there.
    return (
      controls.find((el) => el.checked) ||
      controls.find((el) => !el.matches("button, a")) ||
      controls[0]
    );
  };

  /**
   * Puts a control's inline error id back into its aria-describedby.
   *
   * The maxlength module's counter replaces the attribute with its own id,
   * and again on every keyup and change; this restores the link for the
   * initial focus only.
   *
   * @param {HTMLElement} el
   *   An invalid control.
   */
  const restoreErrorLink = (el) => {
    const error = `${el.id}--error-message`;
    const ids = (el.getAttribute("aria-describedby") || "").split(" ");
    if (el.id && document.getElementById(error) && !ids.includes(error)) {
      el.setAttribute("aria-describedby", [...ids, error].join(" ").trim());
    }
  };

  /**
   * Calls back with a textarea's CKEditor 5 once it has been created.
   *
   * Drupal creates the editor asynchronously and offers no ready event.
   *
   * @param {HTMLTextAreaElement} textarea
   *   The textarea the editor replaces.
   * @param {function} callback
   *   Called with the editor.
   * @param {number} tries
   *   Checks left before giving up.
   */
  // ponytail: polls for up to 3 seconds; an editor slower than that keeps
  // the textarea's link but does not get focus.
  const withEditor = (textarea, callback, tries = 30) => {
    const editor = Drupal.CKEditor5Instances?.get(textarea.dataset.ckeditor5Id);
    if (editor) {
      callback(editor);
    } else if (tries) {
      setTimeout(() => withEditor(textarea, callback, tries - 1), 100);
    }
  };

  /**
   * Moves focus to the first invalid field after a failed form submission.
   *
   * Without it focus stays on the page body, so a screen reader user is not
   * told the submission failed (yalesites-org/YaleSites-Internal#1670). The
   * field's (or its group's) aria-describedby points at its error, so it is
   * announced on focus. A rich text field's editing area gets its textarea's
   * aria-describedby, set through the editor so it is not re-rendered away.
   */
  Drupal.behaviors.ysCoreFormErrorFocus = {
    attach(context) {
      const invalid = once(
        "ys-core-form-error-focus",
        '[aria-invalid="true"]',
        context
      );
      if (!invalid.length) {
        return;
      }
      const describeEditor = (textarea) => (editor) => {
        const { view } = editor.editing;
        view.change((writer) => {
          writer.setAttribute(
            "aria-describedby",
            textarea.getAttribute("aria-describedby"),
            view.document.getRoot()
          );
        });
        if (textarea === invalid[0]) {
          view.focus();
        }
      };
      // Wait for the editor behavior, which may attach after this one.
      setTimeout(() => {
        invalid.forEach(restoreErrorLink);
        invalid
          .filter((el) => el.dataset.ckeditor5Id)
          .forEach((textarea) =>
            withEditor(textarea, describeEditor(textarea))
          );
        if (!invalid[0].dataset.ckeditor5Id) {
          focusTarget(invalid[0])?.focus();
        }
      });
    },
  };
})(Drupal, once);
