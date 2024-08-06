Drupal.behaviors.textLink = {
  attach(context) {
    // Selectors
    const currentURL = window.location.origin;
    const links = context.querySelectorAll('a');

    // Add click event listener for clicking the text
    const elems = document.querySelectorAll('.copy-trigger');
    elems.forEach((elem) => {
      elem.addEventListener(
        'click',
        (event) => {
          // Only fire if the target has id copy
          if (!event.target.matches('.copy-trigger')) return;

          if (!navigator.clipboard) {
            // Clipboard API not available
            return;
          }
          const text =
            event.target.parentNode.querySelector(
              '.pre-text__text',
            ).textContent;
          try {
            navigator.clipboard.writeText(text);
            const triggerValue = elem;
            triggerValue.innerHTML = 'Copied to clipboard';
            setTimeout(() => {
              triggerValue.innerHTML = '(Copy)';
            }, 1200);
          } catch (error) {
            const triggerValue = elem;
            triggerValue.innerHTML = '(error)';
          }
        },
        false,
      );
    });

    // find all external links and add a class
    links.forEach((link) => {
      const linkHref = link.getAttribute('href');

      if (linkHref !== currentURL) {
        link.classList.add('external-link');
      }
    });
  },
};
