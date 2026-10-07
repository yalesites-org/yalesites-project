((Drupal, $, once) => {
  Drupal.behaviors.ysCoreHeaderFooterSettings = {
    attach: function() { // eslint-disable-line
      // Function to handle radio input checked behavior based on radio element selection.
      function handleRadioInputs(radioGroup) {
        // Get references to the radio input elements within the specified group
        const radioInputs = document.querySelectorAll(radioGroup);
        const detailGroups = document.querySelectorAll(
          ".ys-core-footer-settings-form details"
        );

        // Add event listener to each radio input
        radioInputs.forEach((input) => {
          input.addEventListener("change", function () {
            if (this.checked) {
              this.setAttribute("checked", "checked");
              // Remove the 'checked' attribute from other radio inputs
              radioInputs.forEach((otherInput) => {
                if (otherInput !== this) {
                  otherInput.removeAttribute("checked");
                }
              });

              // Closes all details after selecting a new footer variation.
              for (let i = 0; i < detailGroups.length; i++) {
                detailGroups[i].removeAttribute("open");
              }
            }
          });
        });
      }

      // Store radio input groups in an array
      const radioGroups = [
        'input[name="header_variation"]',
        'input[name="footer_variation"]',
        'input[name="nav_position"]',
      ];

      // Apply the function to each radio input group
      radioGroups.forEach((group) => {
        handleRadioInputs(group);
      });
    },
  };

  /**
   * Keeps CKEditor 5 in step with a #states disabled section.
   *
   * CKEditor 5 locks itself into read-only mode when it attaches to a textarea
   * that is disabled at page load, and nothing releases that lock when #states
   * later re-enables the field. On the footer settings form that leaves the
   * Footer Content editor inert - toolbar visible, typing ignored - until the
   * page is reloaded. Re-applying the lock when the section is disabled again
   * matters too: without it a keyboard user could edit a locked section.
   *
   * @see core/modules/ckeditor5/js/ckeditor5.js
   * @see core/misc/states.js
   */
  Drupal.behaviors.ysCoreStatesCkeditor5 = {
    attach(context) {
      once(
        "ys-core-states-ckeditor5",
        ".ys-core-header-footer-settings",
        context
      ).forEach((form) => {
        // jQuery events bubble, so listening on the form catches state changes
        // in its own sections without watching the whole document.
        $(form).on("state:disabled", (event) => {
          // Only react to changes driven by a dependency, matching core.
          if (!event.trigger) {
            return;
          }

          // find() plus addBack() mirrors core, so a #states set straight on a
          // text_format element is covered as well as one set on its wrapper.
          $(event.target)
            .find("textarea[data-ckeditor5-id]")
            .addBack("textarea[data-ckeditor5-id]")
            .each((index, element) => {
              const editor = Drupal.CKEditor5Instances.get(
                element.getAttribute("data-ckeditor5-id")
              );

              // The instance is only registered once its async setup resolves.
              if (!editor) {
                return;
              }

              if (event.value) {
                editor.enableReadOnlyMode("ckeditor5_disabled");
              } else {
                editor.disableReadOnlyMode("ckeditor5_disabled");
              }
            });
        });
      });
    },
  };
})(Drupal, jQuery, once);
