/**
 * @file
 * JavaScript to add Coffee functionality to the "Go to" menu item.
 */

(function ($, Drupal, once) {
  'use strict';

  /**
   * Add Coffee functionality to the "Go to" menu item.
   */
  Drupal.behaviors.ysCoreCoffee = {
    attach: function (context, settings) {
      once('coffee-menu-item', 'body', context).forEach(function () {
        addCoffeeToMenuItem();
      });
    }
  };

  /**
   * Add Coffee functionality to the "Go to" menu item.
   */
  function addCoffeeToMenuItem() {
    // Find the Coffee menu item by its class
    var $coffeeMenuItem = $('.coffee-menu-item');

    if ($coffeeMenuItem.length) {
      // Add click handler to trigger Coffee
      $coffeeMenuItem.on('click', function(e) {
        e.preventDefault();
        // Trigger Coffee if it exists
        if (typeof Drupal.coffee !== 'undefined' && typeof Drupal.coffee.show === 'function') {
          Drupal.coffee.show();
        }
      });

      // Add keyboard shortcut handler (Alt+D)
      $(document).on('keydown', function(e) {
        // Alt+D (keyCode 68 for 'D')
        if (e.altKey && e.keyCode === 68) {
          e.preventDefault();
          if (typeof Drupal.coffee !== 'undefined' && typeof Drupal.coffee.show === 'function') {
            Drupal.coffee.show();
          }
        }
      });
    }
  }

})(jQuery, Drupal, once);
