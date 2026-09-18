/**
 * @file
 * Disables each include/exclude term operator while its multi-select is
 * empty (#1316).
 *
 * "Any vs. all" only means something once at least one tag is chosen, so the
 * control stays disabled — not hidden — until then. The server renders the
 * correct initial disabled state from the field's stored value, but this
 * still re-checks once on attach (not just on future changes) as a defensive
 * catch-all for any case where the select can already hold a value by the
 * time this behavior attaches — e.g. the back/forward cache restoring a page
 * with a selection already made, or Drupal re-attaching behaviors to markup
 * Chosen has already initialized.
 *
 * This can't be done with #states: Drupal's built-in "empty" trigger checks
 * `this.val() === ''` (core/misc/states.js), but jQuery's .val() on a
 * <select multiple> returns null or an array, never an empty string, so that
 * check never matches a multi-select regardless of its actual selection.
 *
 * Toggling the radio inputs' own `disabled` alone isn't enough: #disabled on
 * the radios element also lands on the <fieldset> that
 * CompositeFormElementTrait wraps it in (the radios element has a #title,
 * even an invisible one, so it always gets that wrapper — see the widget's
 * ViewsBasicWidgetBase::buildTermOperator() docblock), and a native
 * `<fieldset disabled>` forces every descendant control disabled regardless
 * of what its own `disabled` attribute says. Clearing only the inputs left
 * the ancestor fieldset still disabling them, so the control stayed
 * unusable even after terms were added. Both have to be toggled together.
 */

(function (Drupal, once, $) {
  // Pairs each multi-select with the operator it disables/enables.
  const REVEALS = [
    {
      selectSuffix: "[terms_include][]",
      operatorClass: "vb-term-operator--include",
    },
    {
      selectSuffix: "[terms_exclude][]",
      operatorClass: "vb-term-operator--exclude",
    },
  ];

  /**
   * Enables or disables the operator's fieldset and radio inputs.
   */
  function refresh(select, operator) {
    const disabled = select.selectedOptions.length === 0;
    const fieldset = operator.querySelector("fieldset");
    if (fieldset) {
      fieldset.disabled = disabled;
    }
    operator.querySelectorAll('input[type="radio"]').forEach(function (input) {
      input.disabled = disabled;
    });
  }

  Drupal.behaviors.ysViewsBasicTermOperator = {
    attach(context) {
      // once()'s context-matching is querySelectorAll-based, so it can only
      // find a "form" that is a *descendant* of context — never context
      // itself. Layout Builder's off-canvas AJAX commonly hands behaviors
      // the form element as context directly, which would silently match
      // nothing. Scoping once() to the select itself sidesteps that: a
      // select is never the context root, so this holds regardless of what
      // context turns out to be.
      //
      // The operator is looked up document-wide rather than via
      // select.closest("form") for the same reason: Layout Builder's
      // off-canvas markup can't be assumed to nest both under a plain
      // <form> the way this was first written to expect. Drupal only ever
      // shows one "Configure block" dialog at a time, so a document-wide
      // lookup for this dialog's own operator class is unambiguous.
      REVEALS.forEach(function (reveal) {
        once(
          "vb-term-operator",
          `select[name$="${reveal.selectSuffix}"]`,
          context
        ).forEach(function (select) {
          const operator = document.querySelector(`.${reveal.operatorClass}`);
          if (!operator) {
            return;
          }
          // Chosen updates the underlying select and calls jQuery's
          // .trigger("change") on it, but that never reaches a native
          // addEventListener("change", ...) — there is no native
          // element.change() method for jQuery to fall back to the way it
          // does for click/focus/submit, so the trigger stays entirely
          // inside jQuery's own event system. Binding through jQuery here
          // (this file already depends on core/jquery) is what actually
          // catches it; a native listener never fires at all.
          $(select).on("change", function () {
            refresh(select, operator);
          });
          refresh(select, operator);
        });
      });
    },
  };
})(Drupal, once, jQuery);
