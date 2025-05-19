(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.unifiedSearch = {
    attach: function (context, settings) {
      once('unified-search', '.inline-search-form', context).forEach(function (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          const query = form.querySelector('.search-input').value;
          const url = form.querySelector('.search-dropdown').value;
          if (query && url) {
            window.location.href = url.replace('{{query}}', encodeURIComponent(query));
          }
        });
      });
    }
  };
})(jQuery, Drupal); 