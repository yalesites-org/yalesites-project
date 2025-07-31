/**
 * @file
 * Layout Builder sidebar state management overrides.
 * 
 * Provides separate state management for Manage Settings and Edit Layout & Content
 * interfaces by overriding Gin's default sidebar behavior.
 */

((Drupal, drupalSettings, once) => {
  'use strict';

  // Storage keys for different contexts
  const STORAGE_KEYS = {
    manageSettings: {
      desktop: 'YaleSites.layoutBuilder.manageSettings.sidebarExpanded.desktop',
      mobile: 'YaleSites.layoutBuilder.manageSettings.sidebarExpanded.mobile',
      width: 'YaleSites.layoutBuilder.manageSettings.sidebarWidth'
    },
    editLayout: {
      desktop: 'YaleSites.layoutBuilder.editLayout.sidebarExpanded.desktop',
      mobile: 'YaleSites.layoutBuilder.editLayout.sidebarExpanded.mobile',  
      width: 'YaleSites.layoutBuilder.editLayout.sidebarWidth'
    }
  };

  // Default states for each context
  const DEFAULT_STATES = {
    manageSettings: {
      desktop: 'true',
      mobile: 'false',
      width: '360px' // Default Gin width with proper unit
    },
    editLayout: {
      desktop: 'false', // Hidden by default
      mobile: 'false',
      width: '400px' // Default pixel width (within Gin's 240px-560px range)
    }
  };

  let currentContext = null;
  let originalGinFunctions = {};

  /**
   * Determine the current Layout Builder context.
   * 
   * @returns {string|null} 'manageSettings', 'editLayout', or null
   */
  function getLayoutBuilderContext() {
    // First try to get context from drupalSettings
    if (drupalSettings && drupalSettings.ysLayouts) {
      return drupalSettings.ysLayouts.context;
    }

    // Fallback: detect from URL
    const path = window.location.pathname;
    if (path.includes('/edit')) {
      return 'manageSettings';
    } else if (path.includes('/layout')) {
      return 'editLayout';
    }

    // Final fallback: detect from DOM elements
    if (document.querySelector('.layout-builder-form')) {
      return 'editLayout';
    } else if (document.querySelector('.node-form')) {
      return 'manageSettings';
    }

    return null;
  }

  /**
   * Get context-specific storage keys.
   * 
   * @param {string} context - The current context
   * @returns {object} Storage keys for the context
   */
  function getStorageKeys(context) {
    return STORAGE_KEYS[context] || STORAGE_KEYS.editLayout;
  }

  /**
   * Get context-specific default states.
   * 
   * @param {string} context - The current context
   * @returns {object} Default states for the context
   */
  function getDefaultStates(context) {
    return DEFAULT_STATES[context] || DEFAULT_STATES.editLayout;
  }

  /**
   * Initialize context-specific storage with defaults if not set.
   * 
   * @param {string} context - The current context
   */
  function initializeStorage(context) {
    const keys = getStorageKeys(context);
    const defaults = getDefaultStates(context);

    // Initialize desktop state if not set
    if (!localStorage.getItem(keys.desktop)) {
      localStorage.setItem(keys.desktop, defaults.desktop);
    }

    // Initialize mobile state if not set
    if (!localStorage.getItem(keys.mobile)) {
      localStorage.setItem(keys.mobile, defaults.mobile);
    }

    // Initialize width if not set, or migrate old unitless values
    let width = localStorage.getItem(keys.width);
    if (!width) {
      localStorage.setItem(keys.width, defaults.width);
    } else if (width && !width.includes('px') && !isNaN(parseInt(width))) {
      // Migrate old unitless values (like "360") to proper pixel values
      const migratedWidth = width + 'px';
      localStorage.setItem(keys.width, migratedWidth);
      console.log('YS Layouts: Migrated width from', width, 'to', migratedWidth);
    }
  }

  /**
   * Get current screen breakpoint.
   * 
   * @returns {string} 'desktop' or 'mobile'
   */
  function getCurrentBreakpoint() {
    return window.innerWidth >= 1024 ? 'desktop' : 'mobile';
  }

  /**
   * Override Gin's showSidebar function.
   */
  function overrideShowSidebar() {
    if (!currentContext) return;

    const keys = getStorageKeys(currentContext);
    const breakpoint = getCurrentBreakpoint();
    const storageKey = breakpoint === 'desktop' ? keys.desktop : keys.mobile;

    // Set context-specific storage
    localStorage.setItem(storageKey, 'true');

    // For Manage Settings, sync our width to Gin's expected key
    if (currentContext === 'manageSettings') {
      const contextWidth = localStorage.getItem(keys.width);
      if (contextWidth) {
        localStorage.setItem('Drupal.gin.sidebarWidth', contextWidth);
      }
    }

    // Call original Gin function for DOM manipulation
    if (originalGinFunctions.showSidebar) {
      originalGinFunctions.showSidebar.call(Drupal.ginSidebar);
    }

    // Apply context-specific styling
    applyContextStyling();
  }

  /**
   * Override Gin's collapseSidebar function.
   */
  function overrideCollapseSidebar() {
    // Don't allow collapsing in Manage Settings context
    if (currentContext === 'manageSettings') {
      return;
    }

    if (!currentContext) return;

    const keys = getStorageKeys(currentContext);
    const breakpoint = getCurrentBreakpoint();
    const storageKey = breakpoint === 'desktop' ? keys.desktop : keys.mobile;

    // Set context-specific storage
    localStorage.setItem(storageKey, 'false');

    // Call original Gin function for DOM manipulation
    if (originalGinFunctions.collapseSidebar) {
      originalGinFunctions.collapseSidebar.call(Drupal.ginSidebar);
    }
  }

  /**
   * Override Gin's toggleSidebar function.
   */
  function overrideToggleSidebar() {
    // Don't allow toggling in Manage Settings context
    if (currentContext === 'manageSettings') {
      return;
    }

    if (!currentContext) return;

    const keys = getStorageKeys(currentContext);
    const breakpoint = getCurrentBreakpoint();
    const storageKey = breakpoint === 'desktop' ? keys.desktop : keys.mobile;
    const isExpanded = localStorage.getItem(storageKey) === 'true';

    if (isExpanded) {
      overrideCollapseSidebar();
    } else {
      overrideShowSidebar();
    }
  }

  /**
   * Apply context-specific styling to the sidebar and body.
   */
  function applyContextStyling() {
    const sidebar = document.getElementById('gin_sidebar');
    const body = document.body;
    
    if (!sidebar || !body) return;

    // Remove existing context classes from both sidebar and body
    sidebar.classList.remove('ys-manage-settings', 'ys-edit-layout');
    body.classList.remove('ys-layout-manage-settings', 'ys-layout-edit-layout');

    // Add current context class to both elements
    if (currentContext === 'manageSettings') {
      sidebar.classList.add('ys-manage-settings');
      body.classList.add('ys-layout-manage-settings');
    } else if (currentContext === 'editLayout') {
      sidebar.classList.add('ys-edit-layout');
      body.classList.add('ys-layout-edit-layout');

      // Set 33% width as default for edit layout context, but allow resizing
      const keys = getStorageKeys(currentContext);
      let width = localStorage.getItem(keys.width);
      
      // If no width is stored, set the default to 33%
      if (!width) {
        width = '33%';
        localStorage.setItem(keys.width, width);
        
        // Only set the CSS property if we're setting the default
        sidebar.style.setProperty('--gin-sidebar-width', width);
      }
      // If width is already stored, let Gin handle it (it will read from localStorage)
    }
  }

  /**
   * Initialize the sidebar based on context-specific storage.
   */
  function initializeSidebar() {
    if (!currentContext) return;

    const keys = getStorageKeys(currentContext);
    const breakpoint = getCurrentBreakpoint();
    const storageKey = breakpoint === 'desktop' ? keys.desktop : keys.mobile;
    const isExpanded = localStorage.getItem(storageKey) === 'true';

    // Apply context styling first
    applyContextStyling();

    // For Manage Settings, always show sidebar
    if (currentContext === 'manageSettings') {
      if (originalGinFunctions.showSidebar) {
        originalGinFunctions.showSidebar.call(Drupal.ginSidebar);
      }
      return;
    }
    if (isExpanded) {
      if (originalGinFunctions.showSidebar) {
        originalGinFunctions.showSidebar.call(Drupal.ginSidebar);
      }
    } else {
      if (originalGinFunctions.collapseSidebar) {
        originalGinFunctions.collapseSidebar.call(Drupal.ginSidebar);
      }
    }
  }


  /**
   * Store original Gin functions and replace with overrides.
   */
  function installOverrides() {
    if (!Drupal.ginSidebar) return;

    // Only install overrides for Manage Settings context
    if (currentContext === 'manageSettings') {
      // Store original functions
      originalGinFunctions = {
        showSidebar: Drupal.ginSidebar.showSidebar,
        collapseSidebar: Drupal.ginSidebar.collapseSidebar,
        toggleSidebar: Drupal.ginSidebar.toggleSidebar
      };

      // Override show/hide/toggle to prevent hiding
      Drupal.ginSidebar.showSidebar = overrideShowSidebar;
      Drupal.ginSidebar.collapseSidebar = overrideCollapseSidebar;
      Drupal.ginSidebar.toggleSidebar = overrideToggleSidebar;
    }
  }

  /**
   * Handle window resize events.
   */
  function handleResize() {
    // Re-initialize sidebar on breakpoint changes
    const wasDesktop = currentBreakpoint === 'desktop';
    const isDesktop = window.innerWidth >= 1024;

    if (wasDesktop !== isDesktop) {
      currentBreakpoint = isDesktop ? 'desktop' : 'mobile';
      initializeSidebar();
    }
  }

  let currentBreakpoint = getCurrentBreakpoint();

  // Drupal behavior for layout builder sidebar overrides
  Drupal.behaviors.layoutBuilderSidebarOverrides = {
    attach: function attach(context) {
      once('layout-builder-sidebar-overrides', 'body', context).forEach(() => {
        // Determine current context
        currentContext = getLayoutBuilderContext();
        
        if (!currentContext) {
          return; // Not in a Layout Builder context
        }

        // Initialize storage for all contexts
        initializeStorage(currentContext);
        
        const keys = getStorageKeys(currentContext);

        // For editLayout, just restore context values to Gin's keys and let Gin work normally
        if (currentContext === 'editLayout') {
          // Restore saved values to Gin's storage BEFORE Gin initializes
          let width = localStorage.getItem(keys.width);
          let desktopExpanded = localStorage.getItem(keys.desktop);
          let mobileExpanded = localStorage.getItem(keys.mobile);
          
          if (!width) {
            width = '400px';
            localStorage.setItem(keys.width, width);
          }
          
          // Set Gin's keys to our context-specific values
          localStorage.setItem('Drupal.gin.sidebarWidth', width);
          localStorage.setItem('Drupal.gin.sidebarExpanded.desktop', desktopExpanded || 'false');
          localStorage.setItem('Drupal.gin.sidebarExpanded.mobile', mobileExpanded || 'false');
          
          // Set the CSS custom property directly to ensure visual update
          document.documentElement.style.setProperty('--gin-sidebar-width', width);
          
          // Trigger layout recalculation
          setTimeout(() => {
            window.dispatchEvent(new Event('resize'));
            if (Drupal.ginSidebar && typeof Drupal.ginSidebar.handleResize === 'function') {
              Drupal.ginSidebar.handleResize();
            }
            const sidebar = document.getElementById('gin_sidebar');
            if (sidebar) {
              sidebar.offsetWidth; // Force reflow
            }
          }, 100);
          
          // Apply styling class
          setTimeout(() => {
            applyContextStyling();
          }, 100);
          
          // Set up real-time drag sync for Edit Layout
          setTimeout(() => {
            const dragHandle = document.getElementById('gin-sidebar-draggable');
            if (dragHandle) {
              const syncAfterDrag = () => {
                setTimeout(() => {
                  const currentGinWidth = localStorage.getItem('Drupal.gin.sidebarWidth');
                  if (currentGinWidth && currentGinWidth !== localStorage.getItem(keys.width)) {
                    localStorage.setItem(keys.width, currentGinWidth);
                  }
                }, 50);
              };
              
              dragHandle.addEventListener('mouseup', syncAfterDrag);
              dragHandle.addEventListener('touchend', syncAfterDrag);
            }
            
            // Backup sync on page unload
            window.addEventListener('beforeunload', () => {
              const stillEditLayout = window.location.pathname.includes('/layout') || 
                                     document.querySelector('.layout-builder-form');
              
              if (stillEditLayout) {
                const currentGinWidth = localStorage.getItem('Drupal.gin.sidebarWidth');
                const currentDesktop = localStorage.getItem('Drupal.gin.sidebarExpanded.desktop');
                const currentMobile = localStorage.getItem('Drupal.gin.sidebarExpanded.mobile');
                
                if (currentGinWidth) localStorage.setItem(keys.width, currentGinWidth);
                if (currentDesktop) localStorage.setItem(keys.desktop, currentDesktop);
                if (currentMobile) localStorage.setItem(keys.mobile, currentMobile);
              }
            });
          }, 500);
          
          return; // Don't do any Gin function overrides - let Gin work normally
        }

        // For manageSettings, restore context values and use function overrides
        if (currentContext === 'manageSettings') {
          // Restore saved values to Gin's storage BEFORE Gin initializes
          let width = localStorage.getItem(keys.width);
          let desktopExpanded = localStorage.getItem(keys.desktop);
          let mobileExpanded = localStorage.getItem(keys.mobile);
          
          // Set Gin's keys to our context-specific values
          const finalWidth = width || '360px';
          localStorage.setItem('Drupal.gin.sidebarWidth', finalWidth);
          localStorage.setItem('Drupal.gin.sidebarExpanded.desktop', desktopExpanded || 'true');
          localStorage.setItem('Drupal.gin.sidebarExpanded.mobile', mobileExpanded || 'false');
          
          // Set the CSS custom property directly to ensure visual update
          document.documentElement.style.setProperty('--gin-sidebar-width', finalWidth);
          
          // Trigger layout recalculation
          setTimeout(() => {
            window.dispatchEvent(new Event('resize'));
            if (Drupal.ginSidebar && typeof Drupal.ginSidebar.handleResize === 'function') {
              Drupal.ginSidebar.handleResize();
            }
            const sidebar = document.getElementById('gin_sidebar');
            if (sidebar) {
              sidebar.offsetWidth; // Force reflow
            }
          }, 100);
          
          // Wait for Gin sidebar to be initialized, then install overrides
          const checkGinSidebar = () => {
            if (Drupal.ginSidebar && typeof Drupal.ginSidebar.init === 'function') {
              installOverrides();
              setTimeout(initializeSidebar, 100);
            } else {
              setTimeout(checkGinSidebar, 100);
            }
          };

          checkGinSidebar();
          
          // Backup sync on page unload
          setTimeout(() => {
            window.addEventListener('beforeunload', () => {
              const stillManageSettings = window.location.pathname.includes('/edit') || 
                                         document.querySelector('.node-form');
              
              if (stillManageSettings) {
                const currentGinWidth = localStorage.getItem('Drupal.gin.sidebarWidth');
                const currentDesktop = localStorage.getItem('Drupal.gin.sidebarExpanded.desktop');
                const currentMobile = localStorage.getItem('Drupal.gin.sidebarExpanded.mobile');
                
                if (currentGinWidth) localStorage.setItem(keys.width, currentGinWidth);
                if (currentDesktop) localStorage.setItem(keys.desktop, currentDesktop);
                if (currentMobile) localStorage.setItem(keys.mobile, currentMobile);
              }
            });
          }, 500);
        }

        // Handle window resize
        window.addEventListener('resize', Drupal.debounce(handleResize, 150));
      });
    }
  };

})(Drupal, drupalSettings, once);