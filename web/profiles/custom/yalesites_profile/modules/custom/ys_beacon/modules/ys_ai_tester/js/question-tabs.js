/**
 * @file
 * Vertical question tabs on the AI Tester comparison and single-run views.
 *
 * Both views render the same tabs widget, so this behavior runs on both pages
 * and nothing in it may assume a panel holds two answer columns.
 */

((Drupal, once) => {
  /**
   * Reveals one question's panel and moves the roving tabindex onto its tab.
   *
   * Only the selected tab stays in the tab order. A comparison can carry dozens
   * of questions, so leaving every tab focusable would put a long walk between
   * a keyboard user and the answers the tabs exist to reach.
   *
   * @param {HTMLElement[]} tabs
   *   Every tab button in this tab list, in DOM order.
   * @param {HTMLElement[]} panels
   *   The panel each tab controls, index-aligned with tabs.
   * @param {number} index
   *   The tab to select.
   * @param {boolean} focus
   *   Whether to move focus onto the newly selected tab.
   */
  function select(tabs, panels, index, focus) {
    tabs.forEach((tab, i) => {
      const selected = i === index;
      tab.setAttribute('aria-selected', selected ? 'true' : 'false');
      tab.tabIndex = selected ? 0 : -1;
      if (panels[i]) {
        panels[i].hidden = !selected;
      }
    });

    if (focus) {
      tabs[index].focus();
    }
  }

  /**
   * Maps a key press to the tab it should move to, wrapping at both ends.
   *
   * @param {string} key
   *   The KeyboardEvent key.
   * @param {number} current
   *   The index of the tab currently holding focus.
   * @param {number} last
   *   The index of the final tab.
   *
   * @return {number}
   *   The tab index to select, or -1 when the key is not one this widget owns.
   */
  function nextIndex(key, current, last) {
    switch (key) {
      case 'ArrowDown':
        return current === last ? 0 : current + 1;
      case 'ArrowUp':
        return current === 0 ? last : current - 1;
      case 'Home':
        return 0;
      case 'End':
        return last;
      default:
        return -1;
    }
  }

  /**
   * Wires one tab list: collapses it to a single panel and binds the keyboard.
   *
   * @param {HTMLElement} root
   *   The tab list wrapper.
   */
  function wire(root) {
    const tabs = Array.from(root.querySelectorAll('[role="tab"]'));
    if (!tabs.length) {
      return;
    }

    const panels = tabs.map((tab) =>
      document.getElementById(tab.getAttribute('aria-controls')),
    );

    // The markup deliberately ships every panel visible so that without
    // JavaScript the view is still a readable stacked list of answers.
    // Collapsing it to one panel is this behavior's job, not the template's.
    const marked = tabs.findIndex(
      (tab) => tab.getAttribute('aria-selected') === 'true',
    );
    select(tabs, panels, Math.max(marked, 0), false);

    // Hands visibility over from the pre-paint CSS rule, which collapses to the
    // first panel only until this attribute appears. Set after the first
    // select() so no frame is governed by neither.
    root.setAttribute('data-ys-qtabs-ready', '');

    tabs.forEach((tab, i) => {
      tab.addEventListener('click', () => select(tabs, panels, i, true));

      tab.addEventListener('keydown', (event) => {
        // Automatic activation: for a vertical tab list the arrows both move
        // focus and select, which is the APG tabs pattern.
        const target = nextIndex(event.key, i, tabs.length - 1);
        if (target === -1) {
          return;
        }

        // Otherwise the arrows scroll the panel alongside the tab list and
        // Home/End jump the whole page.
        event.preventDefault();
        select(tabs, panels, target, true);
      });
    });
  }

  Drupal.behaviors.ysAiTesterQuestionTabs = {
    attach(context) {
      once('ys-qtabs', '[data-ys-qtabs]', context).forEach(wire);
    },
  };
})(Drupal, once);
