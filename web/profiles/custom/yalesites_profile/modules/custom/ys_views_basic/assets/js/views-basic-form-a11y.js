/**
 * @file
 * Accessible roles and keyboard support for the listing block form (#2323).
 *
 * field_group builds the form's tab strip as plain links, and the on/off
 * options render as plain checkboxes. This adds the ARIA Authoring Practices
 * tabs and switch patterns on top, without touching the visual design:
 *
 * - Tabs: tablist / tab / tabpanel roles, aria-controls and aria-labelledby,
 *   aria-selected, a roving tabindex, and Left/Right/Home/End keys.
 * - Activation: automatic. Moving focus to a tab shows its panel; Enter and
 *   Space also activate it (Enter, from field_group, then moves focus into
 *   the panel).
 * - Switches: role="switch" with aria-checked kept in sync. Space still
 *   toggles them natively.
 */
((Drupal, once, $) => {
  // Moves the roving tab stop and aria-selected onto the active tab.
  const syncTabs = (list) => {
    list.querySelectorAll(':scope > li.horizontal-tab-button').forEach((li) => {
      const link = li.querySelector('a');
      const selected = li.classList.contains('selected');
      link.setAttribute('aria-selected', selected ? 'true' : 'false');
      link.setAttribute('tabindex', selected ? '0' : '-1');
    });
    // aria-selected already announces the active tab; field_group's extra
    // "(active tab)" text would read it twice.
    document.getElementById('active-horizontal-tab')?.remove();
  };

  // Keeps the roles in step when a tab is shown by a click or a script.
  const wrapTabFocus = () => {
    const proto = Drupal.HorizontalTab?.prototype;
    if (!proto || proto.ysViewsBasicA11y) {
      return;
    }
    const originalFocus = proto.focus;
    proto.focus = function focus(...args) {
      const result = originalFocus.apply(this, args);
      const list = this.item.closest('[data-horizontal-tabs-list]');
      if (list.length) {
        syncTabs(list[0]);
      }
      return result;
    };
    proto.ysViewsBasicA11y = true;
  };

  const setupTabs = (wrapper) => {
    const list = wrapper.querySelector('[data-horizontal-tabs-list]');
    const items = list
      ? Array.from(list.querySelectorAll(':scope > li.horizontal-tab-button'))
      : [];
    // Nothing to do on small screens, where field_group leaves plain details.
    if (!items.length) {
      return;
    }

    list.setAttribute('role', 'tablist');
    list.setAttribute('aria-label', Drupal.t('Block settings sections'));

    const links = items.map((li) => li.querySelector('a'));
    items.forEach((li, i) => {
      const link = links[i];
      const detailsId = link.getAttribute('href').replace(/^#/, '');
      const panel = document.getElementById(detailsId);
      li.setAttribute('role', 'presentation');
      link.setAttribute('role', 'tab');
      link.id = link.id || `${detailsId}-tab`;
      link.setAttribute('aria-controls', detailsId);
      if (panel) {
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', link.id);
      }
    });

    const activate = (index) => {
      const panel = document.getElementById(
        links[index].getAttribute('aria-controls'),
      );
      const tab = $(panel).data('horizontalTab');
      if (tab) {
        tab.focus();
      }
      links[index].focus();
    };

    links.forEach((link, i) => {
      link.addEventListener('keydown', (event) => {
        const last = links.length - 1;
        const targets = {
          ArrowRight: i === last ? 0 : i + 1,
          ArrowLeft: i === 0 ? last : i - 1,
          Home: 0,
          End: last,
        };
        if (event.key in targets) {
          event.preventDefault();
          activate(targets[event.key]);
        } else if (event.key === ' ') {
          event.preventDefault();
          activate(i);
        }
      });
    });

    syncTabs(list);
  };

  const setupSwitch = (input) => {
    input.setAttribute('role', 'switch');
    input.setAttribute('aria-checked', input.checked ? 'true' : 'false');
    input.addEventListener('change', () => {
      input.setAttribute('aria-checked', input.checked ? 'true' : 'false');
    });
  };

  // eslint-disable-next-line no-param-reassign
  Drupal.behaviors.ysViewsBasicFormA11y = {
    attach(context) {
      wrapTabFocus();
      once('vb-tabs-a11y', '[data-horizontal-tabs]', context).forEach(
        setupTabs,
      );
      // Round slider toggles in the listing form only (Reusable block, Show
      // Search, and the "Filters for visitors" rows). Checkboxes inside any
      // other fieldset ("Result content", "Event options") draw as square
      // boxes and stay checkboxes, and so do the toggles on any other block
      // form opened in the same session.
      once(
        'vb-switch-a11y',
        '.layout-builder-configure-block input[type="checkbox"]',
        context,
      ).forEach((input) => {
        if (
          input.closest('form').querySelector('[data-horizontal-tabs]') &&
          !input.closest('.form-checkboxes') &&
          (!input.closest('fieldset') ||
            input.closest('fieldset.vb-filter-rows'))
        ) {
          setupSwitch(input);
        }
      });
    },
  };
})(Drupal, once, jQuery);
