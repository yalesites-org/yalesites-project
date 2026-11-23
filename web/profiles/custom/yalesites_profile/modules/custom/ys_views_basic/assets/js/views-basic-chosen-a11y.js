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
 *
 * Selected options can be unselected from the list (#2349). Chosen drops
 * active-result from a selected row, so its arrow keys skip it and its
 * clicks ignore it. The handlers below add the missing behavior and call
 * Chosen's own result_deselect(), which updates the real select, rebuilds
 * the list and triggers the same change event as the remove button. Focus
 * stays in the search box, so the active option is li.highlighted.
 */

(function (Drupal, once, $) {
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

  function chosenFor(container) {
    const select = container.previousElementSibling;
    return select ? $(select).data('chosen') : null;
  }

  // Every option row Chosen would show, selected or not, in list order.
  function optionRows(container) {
    return Array.from(
      container.querySelectorAll(
        '.chosen-drop ul.chosen-results > li.active-result, .chosen-drop ul.chosen-results > li.result-selected',
      ),
    );
  }

  function highlight(chosen, row) {
    chosen.result_do_highlight($(row));
  }

  function unselect(container, chosen, row) {
    const index = row.getAttribute('data-option-array-index');
    if (index === null || !chosen.result_deselect(index)) {
      return;
    }
    // Chosen drops the selected chip itself on the remove button path only.
    container
      .querySelectorAll(
        `.search-choice-close[data-option-array-index="${index}"]`,
      )
      .forEach(function (close) {
        close.closest('li').remove();
      });
    chosen.set_search_field_placeholder();
    // The rebuilt row is a normal option again. Keep it active so the next
    // key press continues from the same place.
    const again = container.querySelector(
      `.chosen-results > li[data-option-array-index="${index}"]`,
    );
    if (again) {
      highlight(chosen, again);
    }
  }

  function onKeydown(event, container) {
    delete container.dataset.vbSwallowKeyup;
    const chosen = chosenFor(container);
    if (!chosen || !container.classList.contains('chosen-with-drop')) {
      return;
    }
    const current = chosen.result_highlight ? chosen.result_highlight[0] : null;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      const rows = optionRows(container);
      const at = rows.indexOf(current);
      // No active row yet: let Chosen pick its own first match.
      if (at === -1) {
        return;
      }
      // Up from the first row keeps Chosen's own behavior (close the list).
      if (event.key === 'ArrowUp' && at <= 0) {
        return;
      }
      const next = event.key === 'ArrowDown' ? rows[at + 1] : rows[at - 1];
      if (next) {
        event.preventDefault();
        event.stopImmediatePropagation();
        highlight(chosen, next);
      }
      return;
    }
    const isSpace = event.key === ' ' && chosen.get_search_field_value() === '';
    if (
      (event.key === 'Enter' || isSpace) &&
      current &&
      current.classList.contains('result-selected')
    ) {
      // Chosen selects on keyup, so swallow that key press too.
      container.dataset.vbSwallowKeyup = event.key;
      event.preventDefault();
      event.stopImmediatePropagation();
      unselect(container, chosen, current);
    }
  }

  function onKeyup(event, container) {
    if (container.dataset.vbSwallowKeyup === event.key) {
      delete container.dataset.vbSwallowKeyup;
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }

  function onClick(event, container) {
    const row = event.target.closest('.chosen-results > li.result-selected');
    const chosen = chosenFor(container);
    if (!row || !chosen) {
      return;
    }
    event.preventDefault();
    // Chosen's document click handler would see the detached row and close
    // the list, so keep the list open for unselecting several in a row.
    event.stopPropagation();
    unselect(container, chosen, row);
    const search = container.querySelector('.chosen-search-input');
    if (search) {
      search.focus();
    }
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
        // Capture phase, so these run before Chosen's own handlers.
        container.addEventListener(
          'keydown',
          function (event) {
            onKeydown(event, container);
          },
          true,
        );
        container.addEventListener(
          'keyup',
          function (event) {
            onKeyup(event, container);
          },
          true,
        );
        container.addEventListener('mouseover', function (event) {
          const row = event.target.closest(
            '.chosen-results > li.result-selected',
          );
          const chosen = chosenFor(container);
          if (row && chosen) {
            highlight(chosen, row);
          }
        });
        container.addEventListener('click', function (event) {
          onClick(event, container);
        });
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
})(Drupal, once, jQuery);
