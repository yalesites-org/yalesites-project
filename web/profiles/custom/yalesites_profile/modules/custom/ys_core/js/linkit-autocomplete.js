/**
 * @file
 * Keeps Linkit's suggestion list on screen and attached to its field.
 *
 * Linkit shows its suggestions in a jQuery UI autocomplete menu, which has no
 * size limit and is positioned once, when it opens. In the Layout Builder
 * modal and sidebar that left these problems at laptop sizes:
 *
 * - A common word returns hundreds of suggestions, so the list ran far past
 *   the bottom of the window, and the window behind a modal dialog is locked,
 *   so nothing below the fold could be reached.
 * - The list is positioned against the dialog's fixed frame, not its
 *   scrolling body, so scrolling the dialog moved the field and left the list
 *   where it was.
 * - In the narrow sidebar the list ran past the right edge of the window.
 *
 * Each time the list opens this caps its size to the room on screen, opening
 * it upward when there is more room above the field, so the list scrolls
 * inside itself. jQuery UI's menu already scrolls the focused item into view,
 * so the arrow keys reach every suggestion. While it is open, any scroll or
 * resize places it against the field again, and it closes once the field has
 * scrolled out of sight.
 *
 * Attached by ys_core_linkit_suggestion_list().
 */
((Drupal, $, once) => {
  // Space kept clear between the list and the edge of the window, in px.
  const EDGE_GAP = 16;

  // With less room than this below the field, the list opens upward instead,
  // provided there is more room above.
  const MIN_ROOM_BELOW = 240;

  /**
   * Returns the ancestors of an element that clip their overflow.
   *
   * @param {HTMLElement} element
   *   The element.
   *
   * @return {HTMLElement[]}
   *   The clipping ancestors, nearest first.
   */
  function clippingAncestors(element) {
    const ancestors = [];
    for (
      let el = element.parentElement;
      el && el !== document.body;
      el = el.parentElement
    ) {
      const style = getComputedStyle(el);
      if (style.overflowX !== 'visible' || style.overflowY !== 'visible') {
        ancestors.push(el);
      }
    }
    return ancestors;
  }

  /**
   * Returns the window, less the fixed toolbars at its top and bottom.
   *
   * Drupal.displace tracks those toolbars. Its left and right offsets are
   * ignored: the Layout Builder sidebar registers itself as one, and the list
   * has to be able to sit inside it.
   *
   * @return {{top: number, right: number, bottom: number, left: number}}
   *   The area, in viewport coordinates.
   */
  function windowArea() {
    const offsets = (Drupal.displace && Drupal.displace.offsets) || {};
    const { clientWidth, clientHeight } = document.documentElement;
    return {
      top: offsets.top || 0,
      right: clientWidth,
      bottom: clientHeight - (offsets.bottom || 0),
      left: 0,
    };
  }

  /**
   * Returns the part of the window that clipping ancestors leave visible.
   *
   * @param {HTMLElement[]} clippers
   *   Clipping ancestors, as returned by clippingAncestors().
   *
   * @return {{top: number, right: number, bottom: number, left: number}}
   *   The visible area, in viewport coordinates.
   */
  function visibleArea(clippers) {
    const area = windowArea();
    clippers.forEach((el) => {
      const rect = el.getBoundingClientRect();
      area.top = Math.max(area.top, rect.top);
      area.right = Math.min(area.right, rect.right);
      area.bottom = Math.min(area.bottom, rect.bottom);
      area.left = Math.max(area.left, rect.left);
    });
    return area;
  }

  /**
   * Fits one Linkit field's suggestion list on screen while it is open.
   *
   * @param {HTMLInputElement} input
   *   The Linkit field, with its autocomplete already set up.
   */
  function keepListInView(input) {
    const $input = $(input);
    const menu = $input.autocomplete('widget')[0];
    let clippers = [];
    let frame = 0;
    // Set while the list is open; aborting it removes the listeners.
    let listeners = null;

    menu.classList.add('ys-linkit-suggestions');

    function place() {
      const field = input.getBoundingClientRect();
      const seen = visibleArea(clippers);
      if (field.bottom <= seen.top || field.top >= seen.bottom) {
        $input.autocomplete('close');
        return;
      }

      // The list hangs off the dialog's fixed frame (or the page, outside a
      // dialog), not the dialog's scrolling body, so only the window cuts it
      // off.
      const room = windowArea();
      const roomBelow = room.bottom - field.bottom - EDGE_GAP;
      const roomAbove = field.top - room.top - EDGE_GAP;
      const upward = roomBelow < MIN_ROOM_BELOW && roomAbove > roomBelow;

      // Set before positioning: placing the list above the field aligns its
      // bottom edge, which depends on its capped height.
      menu.style.maxHeight = `${Math.max(upward ? roomAbove : roomBelow, 0)}px`;
      // jQuery UI widens the list to its longest suggestion, which in the
      // narrow sidebar ran past the edge of the window. Never narrower than
      // the field, as in jQuery UI.
      menu.style.maxWidth = `${Math.max(
        room.right - field.left - EDGE_GAP,
        field.width,
      )}px`;
      $(menu).position({
        my: upward ? 'left bottom' : 'left top',
        at: upward ? 'left top' : 'left bottom',
        of: input,
        collision: 'none',
      });
    }

    function stop() {
      if (!listeners) {
        return;
      }
      listeners.abort();
      listeners = null;
      cancelAnimationFrame(frame);
      frame = 0;
    }

    function update() {
      frame = 0;
      // The dialog can close or be rebuilt without the list closing first.
      if (!input.isConnected || $(menu).is(':hidden')) {
        stop();
        return;
      }
      place();
    }

    function schedule() {
      if (!frame) {
        frame = requestAnimationFrame(update);
      }
    }

    function onScroll(event) {
      // Scrolling the list itself does not move the field.
      if (event.target !== menu) {
        schedule();
      }
    }

    function start() {
      if (listeners) {
        return;
      }
      listeners = new AbortController();
      const { signal } = listeners;
      // Scroll events do not bubble; capturing them at the document catches
      // every scrolling container between the field and the window.
      document.addEventListener('scroll', onScroll, {
        capture: true,
        passive: true,
        signal,
      });
      window.addEventListener('resize', schedule, { passive: true, signal });
    }

    // "open" fires every time a new set of suggestions is shown, not only
    // when the list first appears, and after jQuery UI has positioned it.
    $input.on('autocompleteopen', () => {
      clippers = clippingAncestors(input);
      menu.scrollTop = 0;
      place();
      start();
    });
    $input.on('autocompleteclose', stop);
  }

  Drupal.behaviors.ysCoreLinkitAutocomplete = {
    attach(context) {
      once(
        'ys-linkit-autocomplete',
        'input.form-linkit-autocomplete',
        context,
      ).forEach((input) => {
        // Linkit's own behavior, which this library depends on, sets the
        // autocomplete up first. Skip any field it passed over.
        if ($(input).autocomplete('instance')) {
          keepListInView(input);
        }
      });
    },
  };
})(Drupal, jQuery, once);
