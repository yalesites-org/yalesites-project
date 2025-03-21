Drupal.behaviors.tabs = {
  attach(context) {
    // Selectors
    const tabs = context.querySelectorAll('.tabs');
    // Set an extra value to factor into getFirstVisible().
    // We need this because we no longer have a gap amount set in CSS for the UL.
    // If we don't have this the calculation fails after a couple clicks through
    // a tabset with a lot of tabs.
    const offsetAmount = 2;

    // Support the case where multiple tab sets are on the same page.
    tabs.forEach((tabSet) => {
      const TabSet = tabSet;
      const tabNav = TabSet.querySelector('.tabs__nav');
      const tabControls = TabSet.querySelectorAll('.tabs__control');
      const tabLinks = TabSet.querySelectorAll('.tabs__link');
      const tabContainers = TabSet.querySelectorAll('.tabs__container');
      const controlsWidth = TabSet.querySelector(
        '.tabs__control--left',
      ).offsetWidth;
      let activeIndex = 0;
      let overflowDir;

      /**
       * getFirstVisible
       * @description Get the first item that is visible (not overflown).
       * @returns The value of the left edge of the first fully visible item
       *   plus the width of the controls so that things aren't visually hidden
       *   by the absolutely positioned elements.
       */
      function getFirstVisible() {
        const tabsLeft = TabSet.getBoundingClientRect().left;
        const tabsItems = TabSet.querySelectorAll('.tabs__item');
        const visibleItems = [];

        tabsItems.forEach((item) => {
          if (
            item.getBoundingClientRect().right >
            tabsLeft + controlsWidth + offsetAmount
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
       *   minus the width of the controls so that things aren't visually hidden
       *   by the absolutely positioned elements.
       */
      function getLastHidden() {
        const tabsLeft = TabSet.getBoundingClientRect().left;
        const tabsItems = TabSet.querySelectorAll('.tabs__item');
        const hiddenItems = [];

        tabsItems.forEach((item) => {
          if (item.getBoundingClientRect().left < tabsLeft) {
            hiddenItems.push(item);
          }
        });

        return hiddenItems[hiddenItems.length - 1].offsetLeft - controlsWidth;
      }

      /**
       * setOverflow
       * @description Get the positions of the tabs and the tabs__items to
       *   determine whether an overflow situation is in play.
       */
      function setOverflow() {
        const tabsLeft = TabSet.getBoundingClientRect().left;
        const tabsRight = TabSet.getBoundingClientRect().right;
        const firstTabLeft = TabSet.querySelector(
          '.tabs__item:first-child',
        ).getBoundingClientRect().left;
        const lastTabRight = Math.floor(
          TabSet.querySelector('.tabs__item:last-child').getBoundingClientRect()
            .right,
        );

        if (firstTabLeft < tabsLeft) {
          // If left side of first tab is < left side of tabs.
          // And right side of last tab is > right side of tabs.
          if (lastTabRight > tabsRight) {
            if (overflowDir !== 'both') {
              overflowDir = 'both';
              TabSet.setAttribute('data-overflow', 'both');
            }
            // If left side of first tab is < left side of tabs.
            // But right side of last tab is <= right side of tabs.
          } else if (overflowDir !== 'left') {
            overflowDir = 'left';
            TabSet.setAttribute('data-overflow', 'left');
          }
          // If left side of first tab is >= left side of tabs.
          // And right side of last tab is > right side of tabs.
        } else if (lastTabRight > tabsRight) {
          if (overflowDir !== 'right') {
            overflowDir = 'right';
            TabSet.setAttribute('data-overflow', 'right');
          }
          // If left side or first tab is >= left side of tabs.
          // And right side of last tab is <= right side of tabs.
        } else {
          TabSet.setAttribute('data-overflow', 'none');
          overflowDir = 'none';
        }
      }

      /**
       * mouseNav
       * @description Support mouse navigation when horizontal scrolling occurs.
       */
      function mouseNav(direction) {
        // If right
        if (direction === 'right') {
          tabNav.scrollLeft = getFirstVisible();
        } else {
          tabNav.scrollLeft = getLastHidden();
        }
      }

      /**
       * setHeight
       * @description Sets the height of the tabs wrapper to support animating
       *   the height when switching tabs.
       */
      function setHeight() {
        const navHeight = tabNav.offsetHeight;
        const containerHeight = tabContainers[Number(activeIndex)].offsetHeight;
        const totalHeight = navHeight + containerHeight;

        TabSet.style.height = `${totalHeight}px`;
      }

      /**
       * goToTab
       * @description Goes to a specific tab based on index. Returns nothing.
       * @param {Number} index The index of the tab to go to.
       */
      function goToTab(index) {
        if (index !== activeIndex && index >= 0 && index <= tabLinks.length) {
          tabLinks[Number(activeIndex)].removeAttribute('aria-selected');
          tabLinks[Number(activeIndex)].setAttribute('tabindex', '-1');
          tabLinks[Number(index)].setAttribute('aria-selected', 'true');
          tabLinks[Number(index)].removeAttribute('tabindex');
          tabLinks[Number(index)].focus();
          tabContainers[Number(activeIndex)].classList.remove('is-active');
          tabContainers[Number(index)].classList.add('is-active');
          activeIndex = index;
          setHeight();
        }
      }

      /**
       * ensureVisible
       * @description Ensure the focused tab is fully visible (not overflown).
       * @param {HTMLElement} item The focused item.
       */
      function ensureVisible(item) {
        const tabsLeft = TabSet.getBoundingClientRect().left;
        const tabsRight = TabSet.getBoundingClientRect().right;

        // if right side overflows control, set to left + control.
        if (
          Math.floor(item.getBoundingClientRect().right) >
          tabsRight - controlsWidth
        ) {
          // If overflow right or both.
          if (
            TabSet.getAttribute('data-overflow') === 'right' ||
            TabSet.getAttribute('data-overflow') === 'both'
          ) {
            tabNav.scrollLeft = item.offsetLeft - controlsWidth;
          }
        }
        // if left side overflows control, set to left + control.
        else if (
          Math.floor(item.getBoundingClientRect().left) <
          tabsLeft + controlsWidth
        ) {
          // If overflow left or both.
          if (
            TabSet.getAttribute('data-overflow') === 'left' ||
            TabSet.getAttribute('data-overflow') === 'both'
          ) {
            tabNav.scrollLeft = item.offsetLeft - controlsWidth;
          }
        }
      }

      /**
       * handleClick
       * @description Handles click event listeners on each of the links in the
       *   tab navigation. Returns nothing.
       * @param {HTMLElement} link The link to listen for events on.
       * @param {Number} index The index of that link.
       */
      function handleClick(link, index) {
        link.addEventListener('click', (e) => {
          e.preventDefault();
          goToTab(index);
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
       * @description Support keyboard navigation using the left, right, and
       *   down arrow keys through the `keydown` listener.
       *   Support keyboard and mouse users through the `focus` listener.
       */
      tabLinks.forEach((tab, i) => {
        tab.addEventListener('keydown', (e) => {
          let dir;
          if (e.which === 37) {
            // Left.
            dir = i - 1;
          } else if (e.which === 39) {
            // Right.
            dir = i + 1;
          } else if (e.which === 40) {
            // Down.
            dir = 'down';
          } else {
            // Anything else.
            dir = null;
          }

          if (dir !== null) {
            e.preventDefault();
            if (dir === 'down') {
              const activePanel = TabSet.querySelector(
                '.tabs__container.is-active',
              );
              // Focus on the container.
              activePanel.focus();
            } else if (tabLinks[dir]) {
              // Activate the tab.
              goToTab(dir);
            }
          }
        });
        // This also applies when clicked.
        tab.addEventListener('focus', () => {
          ensureVisible(tab);
        });
      });

      /**
       * init
       * @description Initializes the component.
       *   Set the height for later animation.
       *   Set overflow properties.
       *   Add click listener to each tab.
       *   Add click listener to overflow controls.
       *   Add scroll listener to tab nav.
       *   Add resize listener to adjust the height when the browser is resized.
       */
      setHeight();
      setOverflow();

      tabLinks.forEach((tab, index) => {
        handleClick(tab, index);
      });

      tabControls.forEach((control) => {
        control.addEventListener('click', (e) => {
          e.preventDefault();

          if (control.classList.contains('tabs__control--right')) {
            mouseNav('right');
          } else {
            mouseNav('left');
          }
        });
      });

      tabNav.addEventListener('scroll', setOverflow);

      // Resize tab sets when the window is resized.
      window.addEventListener(
        'resize',
        debounce(function runSetHeight() {
          setHeight();
          setOverflow();
        }),
      );
    });
  },
};
