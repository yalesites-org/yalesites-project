/**
 * @file
 * JavaScript for alert settings form confirmation modal.
 */

(function(Drupal) {

  // Open a confimation modal window when trying to create an emergancy alert.
  function confirm() {
    var content = '<div>Please be aware that you have selected the Emergency Alert option. We strongly reccomend that you only use this alert option in the case of an emergency, such as lockdown/safety information, severe weather that requires people to take shelter, or other events with possible detrimental effects on one\'s safety.</div>';
    var confirmationDialog = Drupal.dialog(content, {
      dialogClass: 'confirm-dialog',
      resizable: true,
      closeOnEscape: false,
      width: 600,
      title: Drupal.t('Emergency Alert Confirmation'),
      buttons: [
        {
          text: Drupal.t('Cancel'),
          class: 'button--secondary button',
          click: function() {
            confirmationDialog.close();
          }
        },
        {
          text: Drupal.t('Confirm'),
          class: 'button--primary button',
          click: function() {
            document.querySelector('form.ys-alert-settings').submit();
          }
        }
      ],
    });
    confirmationDialog.showModal();
  }

  // Add click event to the submit button.
  var submitButton = document.querySelector('#edit-submit');
  submitButton.addEventListener('click', function (event) {
    // Launch the modal if the user selected the 'emergancy' alert.
    if (document.querySelector('#edit-type-emergency').checked) {
      event.preventDefault();
      confirm();
    }
  });

})(Drupal);
