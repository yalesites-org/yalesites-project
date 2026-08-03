/**
 * @file
 * Copies the research prompt out of the comparison export modal.
 */

((Drupal, once) => {
  /**
   * Reports the outcome once, to sighted users and to assistive technology.
   *
   * @param {HTMLElement} button
   *   The copy button, used to scope the status element to this modal.
   * @param {string} message
   *   The already-translated message to report.
   */
  function report(button, message) {
    const status = button
      .closest(".ys-compare-export")
      ?.querySelector("[data-ys-copy-status]");
    if (status) {
      status.textContent = message;
    }
    // Drupal.announce owns the live region, so the status span deliberately is
    // not aria-live: that would announce the same message twice.
    Drupal.announce(message);
  }

  Drupal.behaviors.ysAiTesterCompareExport = {
    attach(context) {
      once("ys-compare-export", "[data-ys-copy-target]", context).forEach(
        (button) => {
          button.addEventListener("click", async () => {
            const prompt = document.getElementById(
              button.getAttribute("data-ys-copy-target")
            );
            if (!prompt) {
              return;
            }

            try {
              await navigator.clipboard.writeText(prompt.value.trim());
              report(button, Drupal.t("Prompt copied to the clipboard."));
            } catch (error) {
              // Clipboard access can be refused outright (denied permission, or
              // a non-secure context). Selecting the text leaves the reviewer one
              // keystroke from copying it rather than silently doing nothing.
              prompt.focus();
              prompt.select();
              report(
                button,
                Drupal.t("Press Ctrl/Cmd+C to copy the selected prompt.")
              );
            }
          });
        }
      );
    },
  };
})(Drupal, once);
