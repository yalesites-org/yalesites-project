Drupal.behaviors.menuToggle = {
  attach(context) {
    // Selectors.
    const menuToggle = context.querySelector('.menu-toggle');
    const header = context.querySelector('.site-header');
    const headerOverlay = context.querySelector('.site-header__overlay');
    const body = context.querySelector('body');
    const focusableElements =
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
    // Classes.
    const mainMenuState = 'data-main-menu-state';

    // Function to trap focus when mobile menu is expanded.
    function trapKeyboard(menu) {
      const focusableMenuElements = menu.querySelectorAll(focusableElements);
      const firstFocusableElement = focusableMenuElements[0];
      const lastFocusableElement =
        focusableMenuElements[focusableMenuElements.length - 1];

      menu.addEventListener('keydown', (e) => {
        const isTabPressed = e.key === 'Tab' || e.keyCode === 9;

        if (!isTabPressed) {
          return;
        }

        if (e.shiftKey) {
          if (document.activeElement === firstFocusableElement) {
            e.preventDefault();
            lastFocusableElement.focus();
          }
        } else if (document.activeElement === lastFocusableElement) {
          e.preventDefault();
          firstFocusableElement.focus();
        }
      });
    }

    // Function to toggle the open/closed state of the main menu.
    function toggleMenuState(target, attribute) {
      const newMenuState =
        target.getAttribute(attribute) === 'closed' ? 'open' : 'closed';
      const ariaButtonState =
        target.getAttribute(attribute) === 'closed' ? 'true' : 'false';

      // Set the menu state.
      target.setAttribute(attribute, newMenuState);

      // Set the button aria properties.
      menuToggle.setAttribute('aria-expanded', ariaButtonState);

      // Set the Bg scroll state.
      if (newMenuState === 'open') {
        // Disable scrolling of "background" content.
        body.setAttribute('data-body-frozen', '');
        // Set mobile header height for expanded menu sizing.
        body.style.setProperty(
          '--header-height-mobile',
          `${header.offsetHeight + header.getBoundingClientRect().top}px`,
        );
      } else {
        // Enable scrolling of "background" content.
        body.removeAttribute('data-body-frozen');
      }
    }

    // Show/Hide menu on toggle click.
    if (menuToggle) {
      menuToggle.addEventListener('click', () => {
        toggleMenuState(header, mainMenuState);
        trapKeyboard(header);
      });
    }

    // Hide menu on escape key press.
    document.addEventListener('keyup', (e) => {
      if (e.key === 'Escape') {
        if (header.getAttribute(mainMenuState) === 'open') {
          // Close the main menu if open.
          toggleMenuState(header, mainMenuState);
        }
      }
    });

    // Hide menu on overlay click.
    if (headerOverlay) {
      headerOverlay.addEventListener('click', () => {
        toggleMenuState(header, mainMenuState);
      });
    }
  },
};
