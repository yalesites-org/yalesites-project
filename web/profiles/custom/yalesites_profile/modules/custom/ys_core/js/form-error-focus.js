((Drupal, once) => {
  /**
   * Moves focus to the first invalid field after a failed form submission.
   *
   * Without it focus stays on the page body, so a screen reader user is not
   * told the submission failed (yalesites-org/YaleSites-Internal#1670). The
   * field's aria-describedby points at its error, so it is announced on focus.
   */
  Drupal.behaviors.ysCoreFormErrorFocus = {
    attach(context) {
      const [firstInvalid] = once(
        "ys-core-form-error-focus",
        '[aria-invalid="true"]',
        context
      );
      if (firstInvalid) {
        firstInvalid.focus();
      }
    },
  };
})(Drupal, once);
