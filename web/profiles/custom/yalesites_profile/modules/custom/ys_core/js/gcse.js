((Drupal) => {
  Drupal.behaviors.gcseSearchTab = {
    attach(context, settings) {
      function gcseLoad() {
        const cx = "013786538304926843299:7eimwqn6viu";
        const gcse = document.createElement("script");
        gcse.type = "text/javascript";
        gcse.async = true;
        gcse.src = `https://cse.google.com/cse.js?cx=${cx}`;
        const s = document.getElementsByTagName("script")[0];
        s.parentNode.insertBefore(gcse, s);
        window.gcseLoaded = true;
      }

      gcseLoad();
    },
  };
})(Drupal);
