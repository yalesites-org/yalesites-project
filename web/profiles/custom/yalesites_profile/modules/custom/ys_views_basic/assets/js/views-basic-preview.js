/**
 * @file
 * Live, client-side mockup preview for the Views Basic authoring form (#1318).
 *
 * Reflects the Field Display and Sort/Pinned settings on a static placeholder
 * card as the site builder changes them. No backend query is performed; all
 * updates are client-side. The card parts are shown/hidden based on the
 * matching checkbox state.
 */

(function (Drupal, once) {
  // Map a preview card part (CSS class within the panel) to the form-control
  // name suffix that toggles it, and whether the control's checked state shows
  // (true) or hides (false) the part.
  const TOGGLES = [
    {
      part: ".vb-preview__image",
      suffix: "[field_options][show_thumbnail]",
      showWhenChecked: true,
    },
    {
      part: ".vb-preview__category",
      suffix: "[field_options][show_categories]",
      showWhenChecked: true,
    },
    {
      part: ".vb-preview__tags",
      suffix: "[field_options][show_tags]",
      showWhenChecked: true,
    },
    {
      part: ".vb-preview__eyebrow",
      suffix: "[post_field_options][show_eyebrow]",
      showWhenChecked: true,
    },
    {
      part: ".vb-preview__calendar",
      suffix: "[event_field_options][hide_add_to_calendar]",
      showWhenChecked: false,
    },
    {
      part: ".vb-preview__pinned",
      suffix: "[pinned_to_top]",
      showWhenChecked: true,
    },
  ];

  /**
   * Finds the checkbox controlling a toggle within the given form.
   */
  function findControl(form, suffix) {
    return form.querySelector(`input[type="checkbox"][name$="${suffix}"]`);
  }

  /**
   * Applies every toggle's current state to the preview panel.
   */
  function refresh(panel, form) {
    TOGGLES.forEach(function (toggle) {
      const part = panel.querySelector(toggle.part);
      if (!part) {
        return;
      }
      const control = findControl(form, toggle.suffix);
      // A part whose control is absent for this content type stays as rendered.
      if (!control) {
        return;
      }
      const visible = control.checked === toggle.showWhenChecked;
      part.hidden = !visible;
    });
  }

  Drupal.behaviors.ysViewsBasicPreview = {
    attach(context) {
      once("vb-preview", ".vb-preview", context).forEach(function (panel) {
        const form = panel.closest("form");
        if (!form) {
          return;
        }
        // Bind a change handler to each controlling checkbox.
        TOGGLES.forEach(function (toggle) {
          const control = findControl(form, toggle.suffix);
          if (control) {
            control.addEventListener("change", function () {
              refresh(panel, form);
            });
          }
        });
        // Set the initial state from the saved/default values.
        refresh(panel, form);
      });
    },
  };
})(Drupal, once);
