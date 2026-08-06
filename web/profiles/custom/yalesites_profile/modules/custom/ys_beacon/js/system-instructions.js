/**
 * @file
 * JavaScript for the Beacon system instructions admin interface.
 */

(function systemInstructionsInit(Drupal, drupalSettings, once) {
  /**
   * Character counter for the system instructions WYSIWYG editor.
   *
   * Instructions are stored as Markdown, but the editor holds HTML. There is no
   * Markdown length available client-side, so the counter reports the editor's
   * plain-text length as a close, intuitive equivalent; the server-side
   * validation on the converted Markdown remains authoritative.
   */
  Drupal.behaviors.systemInstructionsCharacterCount = {
    attach(context) {
      const settings = drupalSettings.ysBeaconSystemInstructions || {};
      const maxLength = parseInt(settings.maxLength, 10) || 0;
      const warningThreshold =
        parseInt(settings.warningThreshold, 10) || maxLength;

      const editors = once(
        "ys-beacon-instructions-counter",
        'textarea[name="instructions[value]"]',
        context
      );

      editors.forEach(function processEditor(textarea) {
        const counter = document.getElementById(
          "instructions-character-count"
        );
        if (!counter) {
          return;
        }

        function render(length) {
          counter.textContent = Drupal.t(
            "Content recommended length set to @max characters, remaining: @remaining",
            {
              "@max": maxLength,
              "@remaining": maxLength - length,
            }
          );
          counter.classList.toggle(
            "warning",
            length > warningThreshold && length <= maxLength
          );
          counter.classList.toggle("error", length > maxLength);
        }

        // Strip HTML to approximate the number of authored characters. Parse
        // into an inert document (no script execution or resource loads).
        function textLength(html) {
          const parsed = new DOMParser().parseFromString(html, "text/html");
          return (parsed.body.textContent || "").length;
        }

        // The CKEditor 5 instance attaches asynchronously; wait for it, then
        // count its content. If it never appears (editor disabled), fall back
        // to counting the raw textarea value.
        let attempts = 0;
        function bind() {
          const id = textarea.getAttribute("data-ckeditor5-id");
          const editor =
            id && Drupal.CKEditor5Instances
              ? Drupal.CKEditor5Instances.get(id)
              : null;

          if (editor) {
            const update = function update() {
              render(textLength(editor.getData()));
            };
            editor.model.document.on("change:data", update);
            update();
            return;
          }

          attempts += 1;
          if (attempts < 20) {
            window.setTimeout(bind, 100);
            return;
          }

          // Fallback: no WYSIWYG, count the plain textarea.
          const update = function update() {
            render(textarea.value.length);
          };
          textarea.addEventListener("input", update);
          update();
        }

        bind();
      });
    },
  };
})(Drupal, drupalSettings, once);
