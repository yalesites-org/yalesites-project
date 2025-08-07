/**
 * @file
 * Layout Builder sidebar state management overrides.
 * 
 * Provides separate state management for Manage Settings and Edit Layout & Content
 * interfaces by overriding Gin's default sidebar behavior.
 */

((Drupal, drupalSettings, once) => {
  'use strict';

  /**
   * Configuration object for Layout Builder sidebar behavior.
   * Centralizes all hardcoded values for easier maintenance.
   * @constant {Object}
   */
  const CONFIG = {
    // INTEGRATION POINT: Breakpoint settings must match Gin theme
    // Gin defines $breakpointLarge: 1024px and $breakpointWide: 1280px
    breakpoints: {
      desktop: 1024, // Must match Gin's $breakpointLarge
      wide: 1280     // Must match Gin's $breakpointWide
    },
    
    // Timing delays for DOM operations
    timeouts: {
      LAYOUT_RECALC: 100,
      STYLING_APPLY: 100,
      GIN_CHECK: 100,
      DRAG_SETUP: 500,
      DRAG_SYNC: 50
    },
    
    // Context detection patterns
    detection: {
      urlPatterns: {
        manageSettings: '/edit',
        editLayout: '/layout'
      },
      domSelectors: {
        manageSettings: '.node-form',
        editLayout: '.layout-builder-form'
      }
    },
    
    // Storage configuration
    storage: {
      keyPrefix: 'YaleSites.layoutBuilder',
      // INTEGRATION POINT: Gin theme's native localStorage keys
      // These must match Gin's exact key names for compatibility
      ginKeys: {
        width: 'Drupal.gin.sidebarWidth',
        desktopExpanded: 'Drupal.gin.sidebarExpanded.desktop',
        mobileExpanded: 'Drupal.gin.sidebarExpanded.mobile'
      },
      // Monitoring configuration
      monitoring: {
        enabled: true,
        logChanges: false, // Set to true for debugging
        trackUsage: true,
        maxLogEntries: 100
      }
    },
    
    // Default states and widths
    defaults: {
      manageSettings: {
        desktop: 'true',
        mobile: 'false',
        width: '360px' // Default Gin width
      },
      editLayout: {
        desktop: 'false', // Hidden by default
        mobile: 'false',
        width: '400px' // Within Gin's 240px-560px range
      }
    }
  };

  /**
   * Generate storage keys for a given context.
   * @param {string} context - Context identifier
   * @returns {Object} Storage keys for the context
   */
  function generateStorageKeys(context) {
    const prefix = CONFIG.storage.keyPrefix;
    return {
      desktop: `${prefix}.${context}.sidebarExpanded.desktop`,
      mobile: `${prefix}.${context}.sidebarExpanded.mobile`,
      width: `${prefix}.${context}.sidebarWidth`
    };
  }

  // Generate storage keys for each context
  const STORAGE_KEYS = {
    manageSettings: generateStorageKeys('manageSettings'),
    editLayout: generateStorageKeys('editLayout')
  };

  // Storage monitoring functionality
  const storageMonitor = {
    log: [],
    
    /**
     * Record a localStorage operation for monitoring.
     * @param {string} operation - The operation type (get, set, remove)
     * @param {string} key - The localStorage key
     * @param {string|null} value - The value (for set operations)
     * @param {string} context - The current context
     */
    recordOperation(operation, key, value = null, context = null) {
      if (!CONFIG.storage.monitoring.enabled) return;
      
      const entry = {
        timestamp: Date.now(),
        operation,
        key,
        value,
        context: context || currentContext,
        url: window.location.pathname
      };
      
      this.log.push(entry);
      
      // Limit log size
      if (this.log.length > CONFIG.storage.monitoring.maxLogEntries) {
        this.log.shift();
      }
      
      // Optional console logging for debugging
      if (CONFIG.storage.monitoring.logChanges) {
        console.log('YS Layouts Storage:', entry);
      }
    },
    
    /**
     * Get usage statistics for localStorage operations.
     * @returns {Object} Usage statistics
     */
    getUsageStats() {
      const stats = {
        totalOperations: this.log.length,
        operationTypes: {},
        contexts: {},
        keysAccessed: {},
        timeRange: {
          start: this.log.length > 0 ? this.log[0].timestamp : null,
          end: this.log.length > 0 ? this.log[this.log.length - 1].timestamp : null
        }
      };
      
      this.log.forEach(entry => {
        // Count operations by type
        stats.operationTypes[entry.operation] = (stats.operationTypes[entry.operation] || 0) + 1;
        
        // Count operations by context
        if (entry.context) {
          stats.contexts[entry.context] = (stats.contexts[entry.context] || 0) + 1;
        }
        
        // Count key access
        stats.keysAccessed[entry.key] = (stats.keysAccessed[entry.key] || 0) + 1;
      });
      
      return stats;
    },
    
    /**
     * Export monitoring data for analysis.
     * @returns {Array} Complete log entries
     */
    exportData() {
      return this.log.slice(); // Return copy
    },
    
    /**
     * Clear monitoring log.
     */
    clearLog() {
      this.log = [];
    }
  };

  // Enhanced localStorage wrapper with monitoring
  const monitoredStorage = {
    getItem(key) {
      const value = localStorage.getItem(key);
      storageMonitor.recordOperation('get', key, value);
      return value;
    },
    
    setItem(key, value) {
      localStorage.setItem(key, value);
      storageMonitor.recordOperation('set', key, value);
    },
    
    removeItem(key) {
      localStorage.removeItem(key);
      storageMonitor.recordOperation('remove', key);
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

    // Fallback: detect from URL patterns
    const path = window.location.pathname;
    if (path.includes(CONFIG.detection.urlPatterns.manageSettings)) {
      return 'manageSettings';
    } else if (path.includes(CONFIG.detection.urlPatterns.editLayout)) {
      return 'editLayout';
    }

    // Final fallback: detect from DOM elements
    if (document.querySelector(CONFIG.detection.domSelectors.editLayout)) {
      return 'editLayout';
    } else if (document.querySelector(CONFIG.detection.domSelectors.manageSettings)) {
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
    return CONFIG.defaults[context] || CONFIG.defaults.editLayout;
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
    if (!monitoredStorage.getItem(keys.desktop)) {
      monitoredStorage.setItem(keys.desktop, defaults.desktop);
    }

    // Initialize mobile state if not set
    if (!monitoredStorage.getItem(keys.mobile)) {
      monitoredStorage.setItem(keys.mobile, defaults.mobile);
    }

    // Initialize width if not set, or migrate old unitless values
    let width = monitoredStorage.getItem(keys.width);
    if (!width) {
      monitoredStorage.setItem(keys.width, defaults.width);
    } else if (width && !width.includes('px') && !isNaN(parseInt(width))) {
      // Migrate old unitless values (like "360") to proper pixel values
      const migratedWidth = width + 'px';
      monitoredStorage.setItem(keys.width, migratedWidth);
      console.log('YS Layouts: Migrated width from', width, 'to', migratedWidth);
    }
  }

  /**
   * Get current screen breakpoint.
   * 
   * @returns {string} 'desktop' or 'mobile'
   */
  function getCurrentBreakpoint() {
    return window.innerWidth >= CONFIG.breakpoints.desktop ? 'desktop' : 'mobile';
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
    monitoredStorage.setItem(storageKey, 'true');

    // For Manage Settings, sync our width to Gin's expected key
    if (currentContext === 'manageSettings') {
      const contextWidth = monitoredStorage.getItem(keys.width);
      if (contextWidth) {
        monitoredStorage.setItem(CONFIG.storage.ginKeys.width, contextWidth);
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
    monitoredStorage.setItem(storageKey, 'false');

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
    const isExpanded = monitoredStorage.getItem(storageKey) === 'true';

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
      let width = monitoredStorage.getItem(keys.width);
      
      // If no width is stored, set the default to 33%
      if (!width) {
        width = '33%';
        monitoredStorage.setItem(keys.width, width);
        
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
    const isExpanded = monitoredStorage.getItem(storageKey) === 'true';

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
    const isDesktop = window.innerWidth >= CONFIG.breakpoints.desktop;

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
          let width = monitoredStorage.getItem(keys.width);
          let desktopExpanded = monitoredStorage.getItem(keys.desktop);
          let mobileExpanded = monitoredStorage.getItem(keys.mobile);
          
          if (!width) {
            width = '400px';
            monitoredStorage.setItem(keys.width, width);
          }
          
          // Set Gin's keys to our context-specific values
          monitoredStorage.setItem(CONFIG.storage.ginKeys.width, width);
          monitoredStorage.setItem(CONFIG.storage.ginKeys.desktopExpanded, desktopExpanded || 'false');
          monitoredStorage.setItem(CONFIG.storage.ginKeys.mobileExpanded, mobileExpanded || 'false');
          
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
          }, CONFIG.timeouts.LAYOUT_RECALC);
          
          // Apply styling class
          setTimeout(() => {
            applyContextStyling();
          }, CONFIG.timeouts.STYLING_APPLY);
          
          // Set up real-time drag sync for Edit Layout
          setTimeout(() => {
            const dragHandle = document.getElementById('gin-sidebar-draggable');
            if (dragHandle) {
              const syncAfterDrag = () => {
                setTimeout(() => {
                  const currentGinWidth = monitoredStorage.getItem(CONFIG.storage.ginKeys.width);
                  if (currentGinWidth && currentGinWidth !== monitoredStorage.getItem(keys.width)) {
                    monitoredStorage.setItem(keys.width, currentGinWidth);
                  }
                }, CONFIG.timeouts.DRAG_SYNC);
              };
              
              dragHandle.addEventListener('mouseup', syncAfterDrag);
              dragHandle.addEventListener('touchend', syncAfterDrag);
            }
            
            // Backup sync on page unload
            window.addEventListener('beforeunload', () => {
              const stillEditLayout = window.location.pathname.includes(CONFIG.detection.urlPatterns.editLayout) || 
                                     document.querySelector(CONFIG.detection.domSelectors.editLayout);
              
              if (stillEditLayout) {
                const currentGinWidth = localStorage.getItem(CONFIG.storage.ginKeys.width);
                const currentDesktop = monitoredStorage.getItem(CONFIG.storage.ginKeys.desktopExpanded);
                const currentMobile = monitoredStorage.getItem(CONFIG.storage.ginKeys.mobileExpanded);
                
                if (currentGinWidth) monitoredStorage.setItem(keys.width, currentGinWidth);
                if (currentDesktop) monitoredStorage.setItem(keys.desktop, currentDesktop);
                if (currentMobile) monitoredStorage.setItem(keys.mobile, currentMobile);
              }
            });
          }, CONFIG.timeouts.DRAG_SETUP);
          
          return; // Don't do any Gin function overrides - let Gin work normally
        }

        // For manageSettings, restore context values and use function overrides
        if (currentContext === 'manageSettings') {
          // Restore saved values to Gin's storage BEFORE Gin initializes
          let width = monitoredStorage.getItem(keys.width);
          let desktopExpanded = monitoredStorage.getItem(keys.desktop);
          let mobileExpanded = monitoredStorage.getItem(keys.mobile);
          
          // Set Gin's keys to our context-specific values
          const finalWidth = width || '360px';
          localStorage.setItem(CONFIG.storage.ginKeys.width, finalWidth);
          localStorage.setItem(CONFIG.storage.ginKeys.desktopExpanded, desktopExpanded || 'true');
          monitoredStorage.setItem(CONFIG.storage.ginKeys.mobileExpanded, mobileExpanded || 'false');
          
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
          }, CONFIG.timeouts.LAYOUT_RECALC);
          
          // Wait for Gin sidebar to be initialized, then install overrides
          const checkGinSidebar = () => {
            if (Drupal.ginSidebar && typeof Drupal.ginSidebar.init === 'function') {
              installOverrides();
              setTimeout(initializeSidebar, CONFIG.timeouts.STYLING_APPLY);
            } else {
              setTimeout(checkGinSidebar, CONFIG.timeouts.GIN_CHECK);
            }
          };

          checkGinSidebar();
          
          // Backup sync on page unload
          setTimeout(() => {
            window.addEventListener('beforeunload', () => {
              const stillManageSettings = window.location.pathname.includes(CONFIG.detection.urlPatterns.manageSettings) || 
                                         document.querySelector(CONFIG.detection.domSelectors.manageSettings);
              
              if (stillManageSettings) {
                const currentGinWidth = localStorage.getItem(CONFIG.storage.ginKeys.width);
                const currentDesktop = monitoredStorage.getItem(CONFIG.storage.ginKeys.desktopExpanded);
                const currentMobile = monitoredStorage.getItem(CONFIG.storage.ginKeys.mobileExpanded);
                
                if (currentGinWidth) monitoredStorage.setItem(keys.width, currentGinWidth);
                if (currentDesktop) monitoredStorage.setItem(keys.desktop, currentDesktop);
                if (currentMobile) monitoredStorage.setItem(keys.mobile, currentMobile);
              }
            });
          }, CONFIG.timeouts.DRAG_SETUP);
        }

        // Handle window resize
        window.addEventListener('resize', Drupal.debounce(handleResize, 150));
      });
    }
  };

  // Expose monitoring functionality globally for debugging and analysis
  Drupal.ysLayoutsMonitor = {
    /**
     * Get current storage usage statistics.
     * @returns {Object} Usage statistics
     */
    getStats: function() {
      return storageMonitor.getUsageStats();
    },
    
    /**
     * Export all monitoring data.
     * @returns {Array} Complete log entries
     */
    exportData: function() {
      return storageMonitor.exportData();
    },
    
    /**
     * Clear monitoring log.
     */
    clearLog: function() {
      storageMonitor.clearLog();
    },
    
    /**
     * Enable/disable change logging to console.
     * @param {boolean} enabled - Whether to log changes
     */
    setConsoleLogging: function(enabled) {
      CONFIG.storage.monitoring.logChanges = enabled;
    },
    
    /**
     * Get current monitoring configuration.
     * @returns {Object} Monitoring configuration
     */
    getConfig: function() {
      return CONFIG.storage.monitoring;
    },
    
    /**
     * Get all YaleSites localStorage keys and their values.
     * @returns {Object} Key-value pairs for YaleSites keys
     */
    getAllStorageKeys: function() {
      const keys = {};
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key.startsWith(CONFIG.storage.keyPrefix)) {
          keys[key] = localStorage.getItem(key);
        }
      }
      return keys;
    },
    
    /**
     * Generate a usage report for troubleshooting.
     * @returns {Object} Comprehensive usage report
     */
    generateReport: function() {
      const stats = storageMonitor.getUsageStats();
      const allKeys = this.getAllStorageKeys();
      const config = this.getConfig();
      
      return {
        timestamp: new Date().toISOString(),
        currentContext: currentContext,
        currentUrl: window.location.pathname,
        storageKeys: allKeys,
        usageStats: stats,
        monitoringConfig: config,
        totalLogEntries: storageMonitor.log.length,
        browserInfo: {
          userAgent: navigator.userAgent,
          screenWidth: window.screen.width,
          screenHeight: window.screen.height,
          windowWidth: window.innerWidth,
          windowHeight: window.innerHeight
        }
      };
    }
  };

})(Drupal, drupalSettings, once);