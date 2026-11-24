/**
 * @file
 * Keeps the wizard's Continue button disabled while a content type swap runs.
 *
 * Core's ajax.js disables the radio that triggered a request until it
 * returns, and a disabled input is left out of any form POST made meanwhile.
 * Press Continue in that window and the server sees no content type, falls
 * back to the first one and opens the wrong block's form. Waiting for the
 * swap to finish also means the display modes shown are the chosen type's.
 */
(function ($, Drupal) {
  const FORM_SELECTOR = 'form.ys-views-wizard-form';

  /**
   * Whether a request from one of the wizard's radios is still running.
   *
   * @param {jQuery} $form
   *   The wizard form.
   *
   * @return {boolean}
   *   TRUE while a radio change request is in flight.
   */
  function radioRequestInFlight($form) {
    return Drupal.ajax.instances.some(
      (instance) =>
        instance &&
        instance.ajaxing &&
        $form.find(instance.element).is('input[type="radio"]'),
    );
  }

  /**
   * Syncs Continue with the in-flight state.
   */
  function sync() {
    $(FORM_SELECTOR).each(function () {
      const $form = $(this);
      const $continue = $form.find('input[type="submit"].form-submit');
      const busy = radioRequestInFlight($form);
      if (busy) {
        $continue.prop('disabled', true).data('ysWizardGuarded', true);
      } else if ($continue.data('ysWizardGuarded')) {
        $continue.prop('disabled', false).removeData('ysWizardGuarded');
      }
    });
  }

  // Bound at load rather than from a behavior. The library arrives with the
  // dialog's own Ajax response, after Drupal.attachBehaviors() has already run
  // for that markup on a first open, so an attach() would miss the form.
  // jQuery's global Ajax events fire on the document, and sync() finds the
  // form itself, so one binding per page is enough.
  $(document).on('ajaxSend ajaxComplete ajaxError', sync);
})(jQuery, Drupal);
