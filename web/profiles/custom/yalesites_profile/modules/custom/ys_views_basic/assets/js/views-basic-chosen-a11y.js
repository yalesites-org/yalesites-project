/**
 * @file
 * Screen reader names and roles for the Chosen multi-selects in the Views
 * block form (#2347).
 *
 * Chosen 2.2.1 builds each "remove" button with no name, leaves the selected
 * list unnamed, and hides the closed options list with clip only, so it stays
 * in the accessibility tree. This adds the missing names and roles and hides
 * the closed list from assistive tech. Everything is scoped to
 * .layout-builder-configure-block so Better Exposed Filters widgets on public
 * pages are untouched. Chosen rebuilds the selected items on every change, so
 * the attributes are applied again after each rebuild. Attribute values use
 * the pass-through placeholder (!) because @ would HTML-escape "&" into
 * a literal "&amp;" in the accessible name.
 *
 * The options list is also marked multiselectable, each option reports
 * aria-selected (#2348), and each group header becomes a named
 * role="group" that owns its options. Chosen renders groups as a flat list
 * (a li.group-result header followed by sibling li.group-option rows), and
 * the sticky header CSS and arrow key movement depend on that shape, so the
 * rows are not wrapped: the header owns them with aria-owns instead.
 */

(function (Drupal, once) {
  const SCOPE = '.layout-builder-configure-block';
  const CONTAINER = `${SCOPE} .chosen-container-multi`;

  function labelFor(container) {
    // Chosen inserts its container right after the original select.
    const select = container.previousElementSibling;
    if (!select || select.tagName !== 'SELECT' || !select.id) {
      return '';
    }
    const label = document.querySelector(`label[for="${select.id}"]`);
    return label ? label.textContent.trim() : '';
  }

  function applyResults(results) {
    results.setAttribute('aria-multiselectable', 'true');
    let group = null;
    let owned = [];
    function closeGroup() {
      if (!group) {
        return;
      }
      if (owned.length) {
        group.setAttribute('aria-owns', owned.join(' '));
      } else {
        group.removeAttribute('aria-owns');
      }
    }
    results.querySelectorAll(':scope > li').forEach(function (item) {
      if (item.classList.contains('group-result')) {
        closeGroup();
        group = item;
        owned = [];
        item.setAttribute('role', 'group');
        item.setAttribute('aria-label', item.textContent.trim());
        return;
      }
      if (item.getAttribute('role') !== 'option') {
        closeGroup();
        group = null;
        return;
      }
      item.setAttribute(
        'aria-selected',
        item.classList.contains('result-selected') ? 'true' : 'false',
      );
      if (group && item.classList.contains('group-option') && item.id) {
        owned.push(item.id);
      } else if (!item.classList.contains('group-option')) {
        closeGroup();
        group = null;
      }
    });
    closeGroup();
  }

  function apply(container) {
    const choices = container.querySelector('ul.chosen-choices');
    if (choices) {
      const label = labelFor(container);
      choices.setAttribute('role', 'listbox');
      if (label) {
        choices.setAttribute(
          'aria-label',
          Drupal.t('Selected: !label', { '!label': label }),
        );
      }
      choices.querySelectorAll('li.search-choice').forEach(function (item) {
        item.setAttribute('role', 'option');
        const text = item.querySelector('span');
        const close = item.querySelector('.search-choice-close');
        if (close && text) {
          close.setAttribute(
            'aria-label',
            Drupal.t('Remove !item', { '!item': text.textContent.trim() }),
          );
        }
      });
    }
    const results = container.querySelector('.chosen-drop ul.chosen-results');
    if (results) {
      applyResults(results);
    }
    const drop = container.querySelector('.chosen-drop');
    if (drop) {
      drop.setAttribute(
        'aria-hidden',
        container.classList.contains('chosen-with-drop') ? 'false' : 'true',
      );
    }
  }

  Drupal.behaviors.ysViewsBasicChosenA11y = {
    attach(context) {
      // Chosen may not have built its container when this attaches, so watch
      // the document body's scoped containers as they appear.
      const root = context.querySelectorAll ? context : document;
      once('vb-chosen-a11y', CONTAINER, root).forEach(function (container) {
        apply(container);
        // Chosen toggles classes on every hover and arrow key, so batch the
        // work to one pass per frame.
        let queued = false;
        new MutationObserver(function () {
          if (queued) {
            return;
          }
          queued = true;
          requestAnimationFrame(function () {
            queued = false;
            apply(container);
          });
        }).observe(container, {
          childList: true,
          subtree: true,
          attributes: true,
          attributeFilter: ['class'],
        });
      });
    },
  };
})(Drupal, once);
