((Drupal, once) => {
  const FOCUSABLE =
    'input:not([type="hidden"]), select, textarea, button, a[href], [contenteditable="true"]';

  const TAB_PANE =
    '.vertical-tabs__pane, .js-vertical-tabs__pane, .horizontal-tabs-pane';

  const canFocus = (el) =>
    el.matches(FOCUSABLE) &&
    !el.disabled &&
    el.getAttribute('tabindex') !== '-1' &&
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
    const group = invalid.closest('fieldset, .js-form-item') || invalid;
    const controls = [...group.querySelectorAll(FOCUSABLE)].filter(canFocus);
    // Skip buttons (Gin's help toggle) when a form control is there.
    return (
      controls.find((el) => el.checked) ||
      controls.find((el) => !el.matches('button, a')) ||
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
    const ids = (el.getAttribute('aria-describedby') || '').split(' ');
    if (el.id && document.getElementById(error) && !ids.includes(error)) {
      el.setAttribute('aria-describedby', [...ids, error].join(' ').trim());
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
  // Polls for up to 3 seconds. An editor slower than that keeps the
  // textarea's link but does not get focus.
  const withEditor = (textarea, callback, tries = 30) => {
    const editor = Drupal.CKEditor5Instances?.get(textarea.dataset.ckeditor5Id);
    if (editor) {
      callback(editor);
    } else if (tries) {
      setTimeout(() => withEditor(textarea, callback, tries - 1), 100);
    }
  };

  /**
   * Reveals an element hidden in a closed details, vertical tab, or sidebar.
   *
   * @param {HTMLElement} el
   *   The element to reveal.
   */
  const reveal = (el) => {
    const panes = [];
    let pane = el.closest(TAB_PANE);
    while (pane) {
      panes.push(pane);
      pane = pane.parentElement?.closest(TAB_PANE);
    }
    // Outermost tab first, so an inner tab is in a shown pane when opened.
    panes.reverse().forEach((tabPane) => {
      const $pane = window.jQuery?.(tabPane);
      const tab = $pane?.data('verticalTab') || $pane?.data('horizontalTab');
      if (tab) {
        tab.tabShow();
      } else {
        document
          .querySelector(`.vertical-tabs__menu a[href="#${tabPane.id}"]`)
          ?.click();
      }
    });
    let details = el.closest('details:not([open])');
    while (details) {
      details.open = true;
      details = details.parentElement?.closest('details:not([open])');
    }
  };

  /**
   * Finds the field an error summary link means.
   *
   * Some links name an id the page does not render (a media picker's), so
   * fall back to an element the id prefixes, then the form's first invalid
   * field.
   *
   * @param {string} id
   *   The link's fragment.
   * @param {HTMLElement} link
   *   The summary link.
   *
   * @return {HTMLElement|null}
   *   The element the link points at.
   */
  const linkTarget = (id, link) => {
    const exact = document.getElementById(id);
    if (exact) {
      return exact;
    }
    // A prefix match inside a closed tab has no visible control yet, so take
    // the first that holds any control at all.
    const holdsControl = (el) =>
      el.matches(FOCUSABLE) || el.querySelector(FOCUSABLE);
    return (
      [
        ...(link.closest('form') || document).querySelectorAll(
          `[id^="${CSS.escape(`${id}-`)}"]`,
        ),
      ].find(holdsControl) ||
      (link.closest('form') || document).querySelector('[aria-invalid="true"]')
    );
  };

  /**
   * Sends focus to a visible field, opening what hides it first.
   *
   * @param {HTMLElement} target
   *   The field, or a group holding it.
   */
  const focusField = (target) => {
    reveal(target);
    if (target.dataset.ckeditor5Id) {
      withEditor(target, (editor) => editor.editing.view.focus());
      return;
    }
    // Look inside the target before the group around it, so a wrapper div
    // does not hand focus to an unrelated neighbor.
    const editorField = target.querySelector('[data-ckeditor5-id]');
    if (editorField) {
      withEditor(editorField, (editor) => editor.editing.view.focus());
      return;
    }
    const inside = [target, ...target.querySelectorAll(FOCUSABLE)].filter(
      canFocus,
    );
    const field =
      inside.find((el) => !el.matches('button, a')) ||
      inside[0] ||
      focusTarget(target);
    field?.scrollIntoView({ block: 'center' });
    field?.focus();
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
      // An error summary link opens the tab or sidebar hiding its field, then
      // focuses the field. Core's anchor jump cannot reach a hidden target.
      once(
        'ys-core-error-summary-link',
        '[data-drupal-messages] .messages--error a[href^="#"], .messages--error a[href^="#"]',
        context,
      ).forEach((link) => {
        link.addEventListener('click', (event) => {
          const target = linkTarget(link.getAttribute('href').slice(1), link);
          if (target) {
            event.preventDefault();
            focusField(target);
          }
        });
      });
      const invalid = once(
        'ys-core-form-error-focus',
        '[aria-invalid="true"]',
        context,
      );
      if (!invalid.length) {
        return;
      }
      const describeEditor = (textarea) => (editor) => {
        const { view } = editor.editing;
        view.change((writer) => {
          writer.setAttribute(
            'aria-describedby',
            textarea.getAttribute('aria-describedby'),
            view.document.getRoot(),
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
            withEditor(textarea, describeEditor(textarea)),
          );
        if (!invalid[0].dataset.ckeditor5Id) {
          focusTarget(invalid[0])?.focus();
        }
      });
    },
  };
})(Drupal, once);
