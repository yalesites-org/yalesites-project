((Drupal) => {
  Drupal.behaviors.gcseSearchTab = {
    attach: function (context, settings) {
      gcseLoad();

      function gcseLoad() {
        var cx = '013786538304926843299:7eimwqn6viu';
        var gcse = document.createElement('script');
        gcse.type = 'text/javascript';
        gcse.async = true;
        gcse.src = 'https://cse.google.com/cse.js?cx=' + cx;
        var s = document.getElementsByTagName('script')[0];
        s.parentNode.insertBefore(gcse, s);
        window.gcseLoaded = true;
      }
    }
  };
})(Drupal);
