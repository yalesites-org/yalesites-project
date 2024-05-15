Drupal.behaviors.siteHeader = {
  attach(context) {
    const body = context.querySelector('body');
    const header = context.querySelector('.site-header');

    /**
     * debounce
     * @description Debounce to only run a function at most once every 200ms.
     * @param {} func The function to be run after the timeout.
     */
    const debounce = (func) => {
      let timer;
      return function debounceFunction(event) {
        if (timer) clearTimeout(timer);
        timer = setTimeout(func, 200, event);
      };
    };

    /**
     * setHeaderHeight
     * @description Set the `--site-header-height` variable.
     */
    const setHeaderHeight = () => {
      if (!header || !header.offsetHeight) {
        return;
      }

      body.style.setProperty(
        '--site-header-height',
        `${header.offsetHeight || 0}px`,
      );
    };

    // Determine, and set the site header height variable on page load.
    window.addEventListener('load', () => {
      setHeaderHeight();
    });

    // Update the site header height variable when the window is resized.
    window.addEventListener(
      'resize',
      debounce(function runSetHeight() {
        setHeaderHeight();
      }),
    );
  },
};
