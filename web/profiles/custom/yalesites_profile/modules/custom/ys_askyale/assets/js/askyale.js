Drupal.behaviors.askYale = {
  attach(context, settings) {
    // @see https://stackoverflow.com/questions/5525071/how-to-wait-until-an-element-exists
    function waitForElm(selector) {
      return new Promise((resolve) => {
        if (document.querySelector(selector)) {
          resolve(context.querySelector(selector));
        }

        const observer = new MutationObserver((mutations) => {
          if (document.querySelector(selector)) {
            observer.disconnect();
            resolve(document.querySelector(selector));
          }
        });

        // If you get "parameter 1 is not of type 'Node'" error, see https://stackoverflow.com/a/77855838/492336
        observer.observe(document.body, {
          childList: true,
          subtree: true,
        });
      });
    }

    function interceptModalLinks() {
      const askYaleLaunchModal = context.getElementById(
        "ask-yale-modal-button"
      );

      const launchLinks = context.querySelectorAll("a[href='#launch-askyale']");

      launchLinks.forEach((element) => {
        element.addEventListener("click", (event) => {
          event.preventDefault();
          if (askYaleLaunchModal) {
            askYaleLaunchModal.click();
          }
        });
      });
    }

    waitForElm("#ask-yale-modal-button").then(() => {
      interceptModalLinks();
    });
  },
};
