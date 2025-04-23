Drupal.behaviors.utilityDropdownNav = {
  attach() {
    // Selectors
    const utilityDropdownNavs = document.querySelectorAll(
      '.utility-nav__dropdown',
    );
    const utilityDropdownNavToggles = document.querySelectorAll(
      '.utility-nav__cta[data-cta-control-type="dropdown"]',
    );
    const utilityDropdownNavContents = document.querySelectorAll(
      '.utility-nav__dropdown-content',
    );
    const siteHeader = document.querySelector('.site-header__menu-wrapper');

    // Function to toggle dropdown and adjust aria attributes
    const toggleDropdown = (toggle, nav, content) => {
      const isExpanded = nav.getAttribute('aria-expanded') === 'true';
      nav.setAttribute('aria-expanded', !isExpanded);
      toggle.setAttribute('aria-expanded', !isExpanded);
      content.setAttribute('aria-hidden', isExpanded);
    };

    // Function to close dropdown
    const closeDropdown = (toggle, nav, content) => {
      nav.setAttribute('aria-expanded', false);
      toggle.setAttribute('aria-expanded', false);
      content.setAttribute('aria-hidden', true);
    };

    // Function to adjust dropdown position
    const adjustDropdownPosition = (content, toggle) => {
      const contentElement = content;
      const toggleRect = toggle.getBoundingClientRect();

      if (toggleRect.left < window.innerWidth / 2) {
        contentElement.style.left = '';
        contentElement.style.right = '';
        toggle.classList.add('utility-nav__dropdown-direction-right');
        toggle.classList.remove('utility-nav__dropdown-direction-left');
      } else {
        contentElement.style.left = 'auto';
        contentElement.style.right = '0';
        toggle.classList.remove('utility-nav__dropdown-direction-right');
        toggle.classList.add('utility-nav__dropdown-direction-left');
      }
    };

    // Function to adjust dropdown width based on window size
    const adjustDropdownWidth = (content, utilityDropdownMenu, toggle) => {
      const menuWidthStyle = utilityDropdownMenu;

      // Adjust dropdown width based on window size
      if (window.innerWidth >= 990 && utilityDropdownMenu) {
        const dropdownRect = utilityDropdownMenu.getBoundingClientRect();
        const toggleRect = toggle.getBoundingClientRect();
        const toggleRight = toggleRect.x + toggleRect.width;

        menuWidthStyle.style.width = 'auto'; // Reset width to auto before recalculating
        menuWidthStyle.style.width = `${dropdownRect.width + 40}px`;

        if (toggleRect.x < window.innerWidth / 2) {
          menuWidthStyle.style.maxWidth = `${
            window.innerWidth - toggleRight - 16
          }px`;
        } else {
          menuWidthStyle.style.maxWidth = `${toggleRight - 114}px`;
        }
      } else if (utilityDropdownMenu) {
        menuWidthStyle.style.width = 'auto';
      }

      // Adjust dropdown position if inside the siteHeader
      if (siteHeader) {
        adjustDropdownPosition(content, toggle);
      }
    };

    // Debounce function to limit the rate at which a function can fire
    const debounce = (func, wait) => {
      let timeout;
      return (...args) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
      };
    };

    // Loop through each dropdown
    utilityDropdownNavToggles.forEach((toggle, index) => {
      const nav = utilityDropdownNavs[index];
      const content = utilityDropdownNavContents[index];
      const utilityDropdownMenu = content.querySelector(
        '.utility-nav-dropdown__menu',
      );

      // Initial adjustment
      adjustDropdownWidth(content, utilityDropdownMenu, toggle);

      // Adjust on window resize with debounce
      window.addEventListener(
        'resize',
        debounce(
          () => adjustDropdownWidth(content, utilityDropdownMenu, toggle),
          200,
        ),
      );

      // Event listeners: 'click'
      toggle.addEventListener('click', () => {
        toggleDropdown(toggle, nav, content);
      });

      // Event listeners: 'keydown' (for accessibility)
      toggle.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeDropdown(toggle, nav, content);
        }
      });

      // Event listeners: 'keydown' (for accessibility)
      // add focus to toggle when dropdown is closed
      content.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeDropdown(toggle, nav, content);
          toggle.focus();
        }
      });
    });
  },
};
