(function ($, Drupal) {
   orig_allowInteraction = $.ui.dialog.prototype._allowInteraction;
   $.ui.dialog.prototype._allowInteraction = function(event) {
      if ($(event.target).closest('.cke_dialog').length) {
         return true;
      }
      return orig_allowInteraction.apply(this, arguments);
   };

})(jQuery, Drupal);
