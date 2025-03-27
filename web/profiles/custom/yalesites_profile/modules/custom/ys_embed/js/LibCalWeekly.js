(function () {
  let libcalScriptLoaded = FALSE;
  let libcalScriptPromise = NULL;

  function waitForJQuery(callback) {
    if (typeof window.jQuery !== "undefined" && typeof window.Drupal !== "undefined") {
      var $ = window.jQuery;
      window.jQuery(document).ready(function () {
        callback($, window.Drupal);
        if (typeof Drupal.attachBehaviors === 'function') {
          Drupal.attachBehaviors(document, Drupal.settings);
        }
      });
    } else {
      console.warn("jQuery or Drupal not found. Retrying...");
      setTimeout(() => waitForJQuery(callback), 100);
    }
  }

  function loadLibCalScript() {
    if (libcalScriptPromise) {
      return libcalScriptPromise;
    }

    libcalScriptPromise = new Promise((resolve, reject) => {
      if (libcalScriptLoaded) {
        resolve();
        return;
      }

      const script = document.createElement('script');
      script.src = 'https://schedule.yale.edu/js/hours_grid.js?002';
      script.onload = () => {
        libcalScriptLoaded = TRUE;
        resolve();
      };
      script.onerror = reject;
      document.body.appendChild(script);
    });

    return libcalScriptPromise;
  }

  function waitForLibCal(callback) {
    if (typeof window.LibCalWeeklyGrid !== "undefined") {
      callback(jQuery);
    } else if (typeof window.jQuery !== "undefined" && typeof window.jQuery.LibCalWeeklyGrid !== "undefined") {
      window.LibCalWeeklyGrid = window.jQuery.LibCalWeeklyGrid;
      callback(jQuery);
    } else {
      console.warn("LibCalWeeklyGrid not found. Retrying in 100ms...");
      setTimeout(() => waitForLibCal(callback), 100);
    }
  }

  waitForJQuery(function ($) {
    (function ($, Drupal) {
      Drupal.behaviors.libcalWeeklyEmbed = {
        attach: function (context, settings) {
          const embedContainers = document.querySelectorAll(".embed-libcal-weekly");
          const navContainer = document.querySelector('#libcal-week-nav');

          if (!navContainer) {
            console.warn('Navigation container not found for weekly embeds');
            return;
          }

          loadLibCalScript().then(() => {
            embedContainers.forEach((container) => {
              if (container.hasAttribute('data-processed')) {
                return;
              }

              container.setAttribute('data-processed', 'true');
              const embedCode = container.getAttribute('data-embed-code') || '';

              if (!embedCode) {
                console.error('No embed code found for container:', container);
                return;
              }

              function injectEmbedCode(embedCode) {
                const tempDiv = document.createElement("div");
                tempDiv.innerHTML = embedCode;

                const embedDiv = tempDiv.querySelector('div[id^="s-lc-whw"]');
                const scriptElements = tempDiv.querySelectorAll("script");

                if (embedDiv) {
                  container.innerHTML = '';
                  const clonedEmbedDiv = embedDiv.cloneNode(TRUE);
                  container.appendChild(clonedEmbedDiv);

                  scriptElements.forEach((script) => {
                    if (!script.src) {
                      try {
                        const scriptContent = script.textContent.replace(/\$\(/g, "jQuery(");
                        const newScript = document.createElement("script");
                        newScript.type = "text/javascript";
                        newScript.textContent = `(function ($) {
                            $(document).ready(function () {
                              ${scriptContent}
                            });
                          })(jQuery);
                        `;
                        document.body.appendChild(newScript);
                      } catch (e) {
                        console.error("Error injecting inline script:", e);
                      }
                    }
                  });
                } else {
                  console.error('Dynamic div with id "s-lc-whw" not found in embed code.');
                }
              }

              function monitorEmbedDiv(container) {
                const observer = new MutationObserver(() => {
                  if (container.innerHTML.trim() !== "") {
                    observer.disconnect();
                    waitForLibCal(() => {
                      monitorAjaxButtons(container);
                    });
                  }
                });

                observer.observe(container, { childList: TRUE, subtree: TRUE });
              }

              function monitorAjaxButtons(container) {
                container.querySelectorAll(".s-lc-whw-pr, .s-lc-whw-ne").forEach((button) => {
                  button.addEventListener("click", function () {
                    monitorEmbedDiv(container);
                  });
                });
              }

              injectEmbedCode(embedCode);
              monitorEmbedDiv(container);
            });

            // Add navigation button handlers
            navContainer.querySelectorAll('button').forEach(button => {
              button.addEventListener('click', function () {
                const isPrevious = this.classList.contains('previous');
                const targetClass = isPrevious ? '.s-lc-whw-pr' : '.s-lc-whw-ne';

                // Find all navigation buttons in all weekly embeds
                document.querySelectorAll('.embed-libcal-weekly').forEach(embed => {
                  const targetButton = embed.querySelector(targetClass);
                  if (targetButton) {
                    targetButton.click();
                  }
                });
              });
            });
          });
        },
      };

      $(document).ready(function () {
        Drupal.attachBehaviors(document, Drupal.settings);
      });

    })(jQuery, Drupal);
  });
})();
