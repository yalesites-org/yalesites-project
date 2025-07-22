Drupal.behaviors.secondaryMenuToggle = {
  attach(context) {
    const toggleMenu = (ctx) => {
      // Selectors.
      const secondaryMenuToggle = ctx.querySelector('.secondary-menu-toggle');
      const sectionMenu = ctx.querySelector('.in-this-section__inner');
      const sectionWrapper = ctx.querySelector('.in-this-section');
      const sectionMenuToggleText = ctx.querySelector(
        '.secondary-menu-toggle__text',
      );

      // Apply only up to a max-width of 991px
      if (window.innerWidth <= 991) {
        // set default state
        sectionMenu.setAttribute('aria-expanded', false);
        sectionMenu.setAttribute('aria-hidden', true);
        sectionWrapper.setAttribute('data-secondary-menu-state', 'closed');
        sectionWrapper.setAttribute('data-in-this-section-overflow', 'hidden');
        secondaryMenuToggle.setAttribute('aria-expanded', false);
        sectionMenuToggleText.innerHTML = 'In This Section';

        // Remove existing event listener if any
        const newToggleMenuClickHandler = () => {
          const state =
            sectionWrapper.getAttribute('data-secondary-menu-state') ===
            'closed'
              ? 'open'
              : 'closed';

          sectionMenu.setAttribute('aria-expanded', state === 'open');
          sectionMenu.setAttribute('aria-hidden', state === 'closed');
          secondaryMenuToggle.setAttribute('aria-expanded', state === 'open');
          sectionWrapper.setAttribute('data-secondary-menu-state', state);
          sectionMenuToggleText.innerHTML =
            state === 'open' ? 'Close' : 'In This Section';
        };

        secondaryMenuToggle.removeEventListener(
          'click',
          newToggleMenuClickHandler,
        );
        secondaryMenuToggle.addEventListener(
          'click',
          newToggleMenuClickHandler,
        );
      } else {
        sectionMenu.setAttribute('aria-expanded', true);
        sectionMenu.removeAttribute('aria-hidden');
        sectionWrapper.setAttribute('data-secondary-menu-state', 'loaded');
        sectionWrapper.setAttribute(
          'data-in-this-section-overflow',
          'expanded',
        );
      }
    };

    // Initial call
    toggleMenu(context);

    // Call on resize
    window.addEventListener('resize', () => toggleMenu(context));
  },
};
