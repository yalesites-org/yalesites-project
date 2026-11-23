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
        new MutationObserver(function () {
          apply(container);
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
