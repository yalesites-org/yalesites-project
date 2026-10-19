((Drupal, once, $) => {
  /**
   * Applies Event Calendar filters as the visitor changes them.
   *
   * The Apply button stays the submit path: it carries the Drupal AJAX
   * binding, and it is the no-JS fallback. Here it is hidden (js-hide) and
   * triggered for the visitor. Only the calendar is replaced, so focus stays
   * on the filter that changed, and the server response announces the new
   * result count.
   */
  Drupal.behaviors.ysEventCalendarLiveFilter = {
    attach(context) {
      once(
        "ys-calendar-live-filter",
        "form.event-calendar-filter-form",
        context
      ).forEach((form) => {
        const button = form.querySelector('[type="submit"]');
        if (!button) {
          return;
        }
        const search = form.querySelector(".ys-events-search-input");
        let lastSearch = search ? search.value.trim() : "";
        // Set when a filter changes while a request is in flight; that
        // request's completion then sends one more with the latest values.
        let replayQueued = false;
        let ajax;

        const requestUpdate = () => {
          if (!ajax) {
            ajax = Drupal.ajax.instances.find(
              (instance) => instance && instance.element === button
            );
            if (!ajax) {
              return;
            }
            // Replay after the request ends, whether it succeeded or failed.
            // Deferred: core clears ajax.ajaxing only after success settles.
            const replay = () => {
              if (replayQueued) {
                replayQueued = false;
                setTimeout(requestUpdate);
              }
            };
            const { success, error } = ajax;
            ajax.success = (...args) =>
              Promise.resolve(success.apply(ajax, args)).then(replay);
            // Core's error handler throws, so schedule the replay first.
            ajax.error = (...args) => {
              replay();
              return error.apply(ajax, args);
            };
          }
          if (ajax.ajaxing) {
            replayQueued = true;
            return;
          }
          if (search) {
            lastSearch = search.value.trim();
          }
          // Not ajax.execute(): for a form-bound instance it posts without the
          // form values. Core binds AJAX submit buttons to mousedown.
          $(button).trigger("mousedown");
        };

        // Chosen triggers a jQuery change on the underlying select.
        $(form).on("change", "select", requestUpdate);

        if (search) {
          // The calendar component debounces typing and fires this once the
          // visitor pauses with 3 or more characters, or clears the field.
          search.addEventListener("ys-calendar-search", () => {
            if (search.value.trim() !== lastSearch) {
              requestUpdate();
            }
          });
          // Enter searches right away instead of submitting the page.
          search.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
              event.preventDefault();
              requestUpdate();
            }
          });
        }
      });
    },
  };
})(Drupal, once, jQuery);
