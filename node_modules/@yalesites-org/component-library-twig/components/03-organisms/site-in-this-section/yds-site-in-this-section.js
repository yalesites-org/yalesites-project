Drupal.behaviors.inThisSection = {
  attach(context) {
    // Selectors.
    const inThisSection = context.querySelector('.in-this-section');
    if (!inThisSection) {
      return;
    }
    const secondaryNav = context.querySelector(
      '.secondary-nav.menu-with-toggle',
    );
    const secondaryNavMenu = context.querySelector(
      '.secondary-nav__menu--level-0',
    );
    const inThisSectionNavControls = context.querySelectorAll(
      '.in-this-section__control',
    );
    const inThisSectionLinks = context.querySelectorAll(
      '.secondary-nav__link--level-0',
    );
    const inThisSectionInner = context.querySelector('.in-this-section__inner');
    const controlsWidth = context.querySelector(
      '.in-this-section__control--left',
    ).offsetWidth;
    let scrollIndicatorDir;

    /**
     * setOverflow
     * @description Get the positions of the secondaryNav to determine whether an
     * overflow situation is in play.
     */
    function setOverflow() {
      const leftNavOffset = '16';
      const secondaryNavLeft =
        secondaryNav.getBoundingClientRect().left - leftNavOffset;
      const secondaryNavRight = secondaryNav.getBoundingClientRect().right;
      const firstSecondaryNavLeft = secondaryNav
        .querySelector('.secondary-nav__item--level-0:first-child')
        .getBoundingClientRect().left;
      const lastSecondaryNavRight = Math.floor(
        secondaryNav
          .querySelector('.secondary-nav__item--level-0:last-child')
          .getBoundingClientRect().right,
      );

      if (firstSecondaryNavLeft < secondaryNavLeft) {
        // If left side of first secondaryNav is < left side of secondaryNav.
        // And right side of last secondaryNav is > right side of secondaryNav.
        if (lastSecondaryNavRight > secondaryNavRight) {
          if (scrollIndicatorDir !== 'both') {
            scrollIndicatorDir = 'both';
            inThisSectionInner.setAttribute('data-scroll-indicator', 'both');
          }
          // If left side of first secondaryNav is < left side of secondaryNav.
          // But right side of last secondaryNav is <= right side of secondaryNav.
        } else if (scrollIndicatorDir !== 'left') {
          scrollIndicatorDir = 'left';
          inThisSectionInner.setAttribute('data-scroll-indicator', 'left');
        }
        // If left side of first secondaryNav is >= left side of secondaryNav.
        // And right side of last secondaryNav is > right side of secondaryNav.
      } else if (lastSecondaryNavRight > secondaryNavRight) {
        if (scrollIndicatorDir !== 'right') {
          scrollIndicatorDir = 'right';
          inThisSectionInner.setAttribute('data-scroll-indicator', 'right');
        }
        // If left side of first secondaryNav is >= left side of secondaryNav.
        // And right side of last secondaryNav is <= right side of secondaryNav.
      } else {
        scrollIndicatorDir = 'none';
        inThisSectionInner.setAttribute('data-scroll-indicator', 'none');
      }
    }

    /**
     * mouseNav
     * @description Support mouse navigation when horizontal scrolling occurs.
     */
    function mouseNav(direction) {
      const scrollAmount =
        secondaryNavMenu.querySelector('.secondary-nav__item--level-0')
          .offsetWidth * 2;
      secondaryNavMenu.scrollLeft +=
        direction === 'right' ? scrollAmount : -scrollAmount;
    }

    /**
     * ensureVisible
     * @description Ensure the focused tab is fully visible (not overflown).
     * @param {HTMLElement} item The focused item.
     */
    function ensureVisible(item) {
      const secondaryNavLeft = secondaryNav.getBoundingClientRect().left;
      const secondaryNavRight = secondaryNav.getBoundingClientRect().right;

      // if right side overflows control, set to left + control.
      if (
        Math.floor(item.getBoundingClientRect().right) >
        secondaryNavRight - controlsWidth
      ) {
        // If overflow right or both.
        if (
          inThisSectionInner.getAttribute('data-scroll-indicator') ===
            'right' ||
          inThisSectionInner.getAttribute('data-scroll-indicator') === 'both'
        ) {
          secondaryNavMenu.scrollLeft =
            item.parentElement.offsetLeft - controlsWidth;
        }
      }
      // If left side overflows control, set to left + control.
      else if (
        Math.floor(item.getBoundingClientRect().left) <
        secondaryNavLeft + controlsWidth
      ) {
        // If overflow left or both.
        if (
          inThisSectionInner.getAttribute('data-scroll-indicator') === 'left' ||
          inThisSectionInner.getAttribute('data-scroll-indicator') === 'both'
        ) {
          secondaryNavMenu.scrollLeft =
            item.parentElement.offsetLeft - controlsWidth;
        }
      }
    }

    /**
     * debounce
     * @description Debounce to only run a function at most once every 200ms.
     * @param {} func The function to be run after the timeout.
     */
    function debounce(func) {
      let timer;
      return function debounceFunction(event) {
        if (timer) clearTimeout(timer);
        timer = setTimeout(func, 200, event);
      };
    }

    /**
     * linksListeners
     * @description Support focus visualization.
     */
    inThisSectionLinks.forEach((link) => {
      link.addEventListener('focus', () => {
        ensureVisible(link);
      });
    });

    /**
     * init
     */
    setOverflow();

    inThisSectionNavControls.forEach((control) => {
      control.addEventListener('click', (e) => {
        e.preventDefault();

        if (control.classList.contains('in-this-section__control--right')) {
          mouseNav('right');
        } else {
          mouseNav('left');
        }
      });
    });

    secondaryNavMenu.addEventListener('scroll', setOverflow);

    // Listen for window resize.
    window.addEventListener(
      'resize',
      debounce(function resizeListener() {
        setOverflow();
      }),
    );
  },
};
