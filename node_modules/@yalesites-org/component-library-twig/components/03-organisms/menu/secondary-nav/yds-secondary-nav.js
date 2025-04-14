Drupal.behaviors.secondaryNav = {
  attach(context) {
    // Selectors
    const secondaryNav = context.querySelector('.secondary-nav');
    const secondaryNavToggles = context.querySelectorAll(
      '.secondary-nav__toggle--level-0',
    );

    // Function to show a menu.
    const show = (toggle) => {
      const nav = toggle.nextElementSibling;

      toggle.setAttribute('aria-expanded', true);
      nav.style.setProperty('--open-nav-height', `${nav.scrollHeight}px`);

      // Position the submenu to align it with its parent because it is position fixed due to the secondary menu being used inside of
      // the in-this-section component and needing to scroll left/right depending on how many items are in the menu.
      const parentLi = toggle.closest('li');
      const submenu = parentLi.querySelector('.secondary-nav__menu--level-1');
      const parentLiWidth = parentLi.offsetWidth;
      if (submenu) {
        const parentRect = parentLi.getBoundingClientRect();
        // in-this-section__inner is the container which is positioned relative.
        const inThisSectionInner = parentLi.closest('.in-this-section__inner');
        const sectionRect = inThisSectionInner.getBoundingClientRect();
        submenu.style.left = `${parentRect.left - sectionRect.left}px`;
        submenu.style.maxWidth = `${parentLiWidth + 150}px`;
      }
    };

    // Function to hide a menu.
    const hide = (toggle) => {
      toggle.setAttribute('aria-expanded', false);
    };

    // Function to hide all menus.
    const hideAll = () => {
      secondaryNavToggles.forEach((toggle) => {
        hide(toggle);
      });
    };

    // Function to close dropdown when tabbing out of the expended menu.
    function tabOut(toggle, menu) {
      const parent = toggle.parentElement;
      const menuLinks = menu.querySelectorAll('.secondary-nav__link');
      const lastItem = menuLinks[menuLinks.length - 1];

      // Function to close an expanded menu when a user tabs out of it.
      parent.addEventListener('keydown', (e) => {
        const isTabPressed = e.key === 'Tab' || e.keyCode === 9;

        // If the key pressed isn't "tab" return early.
        if (!isTabPressed) {
          return;
        }

        if (e.shiftKey) {
          if (document.activeElement === toggle) {
            // Close when shift-tabbing from the toggle element.
            hide(toggle);
          }
        } else if (document.activeElement === lastItem) {
          // Close when tabbing from the last nested item to a new top-level item.
          hide(toggle);
        }
      });
    }

    // Function to toggle the open/closed state of the main menu.
    function toggleMenuState(target) {
      const ariaButtonState =
        target.getAttribute('aria-expanded') === 'true' ? 'false' : 'true';

      // If opening an item, close all nav items.
      if (ariaButtonState === 'true') {
        hideAll();
        show(target);

        // Pass the expanded menu and related toggle to the tabOut function.
        tabOut(target, target.nextElementSibling);
      } else {
        // Set the button aria attribute.
        hide(target);
      }
    }

    // Show/Hide menu on toggle click.
    secondaryNavToggles.forEach((button) => {
      button.addEventListener('click', () => {
        toggleMenuState(button);
      });
    });

    window.addEventListener('click', (e) => {
      if (secondaryNav) {
        if (!secondaryNav.contains(e.target)) {
          hideAll();
        }
      }
    });
  },
};
