Drupal.behaviors.breadcrumbs = {
  attach(context) {
    // Selectors.
    const breadcrumbsWrapper = context.querySelector(
      '.breadcrumbs__wrapper--collapsible',
    );
    if (!breadcrumbsWrapper) {
      return;
    }
    const breadcrumbs = context.querySelector('.breadcrumbs--collapsible');
    const breadcrumbsMenu = context.querySelector('.breadcrumbs__menu');
    const breadcrumbsControls = context.querySelectorAll(
      '.breadcrumbs__control',
    );
    const breadcrumbsLinks = context.querySelectorAll('.breadcrumbs__link');
    const breadcrumbsButton = context.querySelector('.breadcrumbs__button');
    const breadcrumbsInner = context.querySelector('.breadcrumbs__inner');
    const controlsWidth = context.querySelector(
      '.breadcrumbs__control--left',
    ).offsetWidth;
    let scrollIndicatorDir;

    /**
     * getFirstVisible
     * @description Get the first item that is visible (not overflown).
     * @returns The value of the left edge of the first fully visible item plus
     * the width of the controls so that things aren't visually hidden by the
     * absolutely positioned elements.
     */
    function getFirstVisible() {
      const breadcrumbsLeft = breadcrumbs.getBoundingClientRect().left;
      const breadcrumbsItems =
        breadcrumbs.querySelectorAll('.breadcrumbs__item');
      const visibleItems = [];

      breadcrumbsItems.forEach((item) => {
        if (
          item.getBoundingClientRect().right >
          breadcrumbsLeft + controlsWidth
        ) {
          visibleItems.push(item);
        }
      });

      return visibleItems[1].offsetLeft - controlsWidth;
    }

    /**
     * getLastHidden
     * @description Get the last item that is overflown (not visible).
     * @returns The value of the left edge of the first partially hidden item
     * minus the width of the controls so that things aren't visually hidden by
     * the absolutely positioned elements.
     */
    function getLastHidden() {
      const breadcrumbsLeft = breadcrumbs.getBoundingClientRect().left;
      const breadcrumbsItems =
        breadcrumbs.querySelectorAll('.breadcrumbs__item');
      const hiddenItems = [];

      breadcrumbsItems.forEach((item) => {
        if (item.getBoundingClientRect().left < breadcrumbsLeft) {
          hiddenItems.push(item);
        }
      });

      // @TODO: if the user clicks the left arrow twice quickly when only the
      // first item is hidden (and before the arrow can disappear), this line
      // throws a js error. Not a huge deal, but could probably be refactored to
      // prevent it from happening.
      return hiddenItems[hiddenItems.length - 1].offsetLeft - controlsWidth;
    }

    /**
     * setOverflow
     * @description Get the positions of the breadcrumbs to determine whether an
     * overflow situation is in play.
     */
    function setOverflow() {
      const breadcrumbsLeft = breadcrumbs.getBoundingClientRect().left;
      const breadcrumbsRight = breadcrumbs.getBoundingClientRect().right;
      const firstBreadcrumbsLeft = breadcrumbs
        .querySelector('.breadcrumbs__item:first-child')
        .getBoundingClientRect().left;
      const lastBreadcrumbsRight = Math.floor(
        breadcrumbs
          .querySelector('.breadcrumbs__item:last-child')
          .getBoundingClientRect().right,
      );

      if (firstBreadcrumbsLeft < breadcrumbsLeft) {
        // If left side of first breadcrumb is < left side of breadcrumbs.
        // And right side of last breadcrumb is > right side of breadcrumbs.
        if (lastBreadcrumbsRight > breadcrumbsRight) {
          if (scrollIndicatorDir !== 'both') {
            scrollIndicatorDir = 'both';
            breadcrumbsInner.setAttribute('data-scroll-indicator', 'both');
          }
          // If left side of first breadcrumb is < left side of breadcrumbs.
          // But right side of last breadcrumb is <= right side of breadcrumbs.
        } else if (scrollIndicatorDir !== 'left') {
          scrollIndicatorDir = 'left';
          breadcrumbsInner.setAttribute('data-scroll-indicator', 'left');
        }
        // If left side of first breadcrumb is >= left side of breadcrumbs.
        // And right side of last breadcrumb is > right side of breadcrumbs.
      } else if (lastBreadcrumbsRight > breadcrumbsRight) {
        if (scrollIndicatorDir !== 'right') {
          scrollIndicatorDir = 'right';
          breadcrumbsInner.setAttribute('data-scroll-indicator', 'right');
        }
        // If left side of first breadcrumb is >= left side of breadcrumbs.
        // And right side of last breadcrumb is <= right side of breadcrumbs.
      } else {
        scrollIndicatorDir = 'none';
        breadcrumbsInner.setAttribute('data-scroll-indicator', 'none');
      }
    }

    /**
     * mouseNav
     * @description Support mouse navigation when horizontal scrolling occurs.
     */
    function mouseNav(direction) {
      breadcrumbsMenu.scrollLeft =
        direction === 'right' ? getFirstVisible() : getLastHidden();
    }

    /**
     * ensureVisible
     * @description Ensure the focused tab is fully visible (not overflown).
     * @param {HTMLElement} item The focused item.
     */
    function ensureVisible(item) {
      const breadcrumbsLeft = breadcrumbs.getBoundingClientRect().left;
      const breadcrumbsRight = breadcrumbs.getBoundingClientRect().right;

      // if right side overflows control, set to left + control.
      if (
        Math.floor(item.getBoundingClientRect().right) >
        breadcrumbsRight - controlsWidth
      ) {
        // If overflow right or both.
        if (
          breadcrumbsInner.getAttribute('data-scroll-indicator') === 'right' ||
          breadcrumbsInner.getAttribute('data-scroll-indicator') === 'both'
        ) {
          breadcrumbsMenu.scrollLeft =
            item.parentElement.offsetLeft - controlsWidth;
        }
      }
      // If left side overflows control, set to left + control.
      else if (
        Math.floor(item.getBoundingClientRect().left) <
        breadcrumbsLeft + controlsWidth
      ) {
        // If overflow left or both.
        if (
          breadcrumbsInner.getAttribute('data-scroll-indicator') === 'left' ||
          breadcrumbsInner.getAttribute('data-scroll-indicator') === 'both'
        ) {
          breadcrumbsMenu.scrollLeft =
            item.parentElement.offsetLeft - controlsWidth;
        }
      }
    }

    /**
     * showAllBreadcrumbs
     * @description remove breadcrumbs-overflow value.
     */
    function showAllBreadcrumbs() {
      breadcrumbsWrapper.setAttribute('data-breadcrumbs-overflow', 'expanded');
      breadcrumbsButton.setAttribute('aria-expanded', 'true');
    }

    // Show all breadcrumbs on mobile.
    if (breadcrumbsButton) {
      breadcrumbsButton.addEventListener('click', () => {
        showAllBreadcrumbs();
        setOverflow();
      });
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
    breadcrumbsLinks.forEach((link) => {
      link.addEventListener('focus', () => {
        ensureVisible(link);
      });
    });

    /**
     * init
     */
    setOverflow();

    breadcrumbsControls.forEach((control) => {
      control.addEventListener('click', (e) => {
        e.preventDefault();

        if (control.classList.contains('breadcrumbs__control--right')) {
          mouseNav('right');
        } else {
          mouseNav('left');
        }
      });
    });

    breadcrumbsMenu.addEventListener('scroll', setOverflow);

    // Listen for window resize.
    window.addEventListener(
      'resize',
      debounce(function resizeListener() {
        setOverflow();
      }),
    );
  },
};
