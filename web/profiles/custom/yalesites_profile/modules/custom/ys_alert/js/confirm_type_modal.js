/**
 * @file
 * Confirmation modal for publishing an emergency site-wide alert.
 *
 * Saving the alert settings form is deliberately paused when the Emergency
 * type is selected, so the editor has to confirm before an emergency alert
 * goes live. The pause is easy to miss, so an inline warning is written into
 * the form as well and stays there until the alert is confirmed.
 */

/* global once */

(() => {
  /**
   * Writes the "not saved yet" warning into the form's live region.
   *
   * @param {HTMLElement} region
   *   The empty live region rendered by AlertSettings::buildForm().
   */
  function showPendingNotice(region) {
    const message = new Drupal.Message(region);
    // Clear first so a repeated save attempt re-announces the warning.
    message.clear();
    message.add(
      Drupal.t(
        "Your changes have not been saved yet. An emergency alert has to be confirmed before it goes live: choose Confirm in the confirmation window to publish it, or Cancel to keep editing."
      ),
      { type: "warning", id: "ys-alert-emergency-pending" }
    );
  }

  /**
   * Builds the emergency confirmation dialog.
   *
   * @param {HTMLFormElement} form
   *   The alert settings form, submitted once the editor confirms.
   *
   * @return {Drupal.dialog~dialogDefinition}
   *   The dialog instance.
   */
  function buildDialog(form) {
    const content = document.createElement("div");
    content.textContent = Drupal.t(
      "Please be aware that you have selected the Emergency Alert option. We strongly recommend that you only use this alert option in the case of an emergency, such as lockdown/safety information, severe weather that requires people to take shelter, or other events with possible detrimental effects on one's safety."
    );

    const dialog = Drupal.dialog(content, {
      // jQuery UI only honours "dialogClass" in back-compat mode, which Drupal
      // does not enable, so use the supported "classes" option instead. The
      // default "ui-corner-all" is repeated here because "classes" replaces it.
      classes: { "ui-dialog": "ui-corner-all confirm-dialog" },
      resizable: true,
      closeOnEscape: false,
      width: 600,
      title: Drupal.t("Emergency Alert Confirmation"),
      buttons: [
        {
          text: Drupal.t("Cancel"),
          class: "button--secondary button",
          click() {
            dialog.close();
          },
        },
        {
          text: Drupal.t("Confirm"),
          class: "button--primary button",
          click() {
            form.submit();
          },
        },
      ],
    });

    return dialog;
  }

  Drupal.behaviors.ysAlertConfirmTypeModal = {
    attach(context) {
      once("ys-alert-confirm-type", "form.ys-alert-settings", context).forEach(
        (form) => {
          const submitButton = form.querySelector("#edit-submit");
          const emergency = form.querySelector("#edit-type-emergency");
          const region = form.querySelector(".ys-alert-emergency-notice");

          if (!submitButton || !emergency || !region) {
            return;
          }

          submitButton.addEventListener("click", (event) => {
            if (!emergency.checked) {
              return;
            }
            // Hold the save back until the editor confirms in the dialog.
            event.preventDefault();
            showPendingNotice(region);
            buildDialog(form).showModal();
          });
        }
      );
    },
  };
})();
