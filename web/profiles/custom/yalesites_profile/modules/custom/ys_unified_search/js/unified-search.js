(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.unifiedSearch = {
    attach: function (context, settings) {
      once('unified-search', '.inline-search-form', context).forEach(function (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          const query = form.querySelector('.search-input').value.trim();
          const select = form.querySelector('.search-dropdown');
          let url = select.value;

          if (query && url) {
            url = url.replace('{{query}}', encodeURIComponent(query));
            window.location.href = url;
          }
        });
      });
    }
  };
})(jQuery, Drupal); 