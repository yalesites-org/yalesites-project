(function () {
  let libcalScriptLoaded = false;
  let libcalScriptPromise = null;

  function waitForJQuery(callback) {
    if (typeof window.jQuery !== "undefined" && typeof window.Drupal !== "undefined") {
        var $ = window.jQuery;

        window.jQuery(document).ready(function () {
            callback($, window.Drupal);

            // ✅ Reattach Drupal behaviors to ensure dropdowns work
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
      script.src = 'https://schedule.yale.edu/js/hours_today.js';
      script.onload = () => {
        libcalScriptLoaded = true;
        resolve();
      };
      script.onerror = reject;
      document.body.appendChild(script);
    });

    return libcalScriptPromise;
  }

  function waitForLibCal(callback) {
    if (typeof window.LibCalTodayHours !== "undefined") {
      callback(jQuery);
    } else if (typeof window.jQuery !== "undefined" && typeof window.jQuery.LibCalTodayHours !== "undefined") {
      window.LibCalTodayHours = window.jQuery.LibCalTodayHours;
      callback(jQuery);
    } else {
      console.warn("🚨 LibCalTodayHours not found. Retrying in 100ms...");
      setTimeout(() => waitForLibCal(callback), 100);
    }
  }

  waitForJQuery(function ($) {
    (function ($, Drupal) {
      Drupal.behaviors.libcalEmbed = {
        attach: function (context, settings) {
          // Find all LibCal embed containers
          const embedContainers = document.querySelectorAll(".embed-libcal");
          
          // Load LibCal script once for all instances
          loadLibCalScript().then(() => {
            embedContainers.forEach((container, index) => {
              // Skip if this container has already been processed
              if (container.hasAttribute('data-processed')) {
                return;
              }
              
              // Mark this container as processed
              container.setAttribute('data-processed', 'true');
              
              // Get the embed code from the data attribute
              const embedCode = container.getAttribute('data-embed-code') || '';
              
              if (!embedCode) {
                console.error('No embed code found for container:', container);
                return;
              }

              function injectEmbedCode(embedCode) {
                function decodeHtmlEntities(html) {
                  var textArea = document.createElement("textarea");
                  textArea.innerHTML = html;
                  return textArea.value;
                }
              
                var tempDiv = document.createElement("div");
                tempDiv.innerHTML = decodeHtmlEntities(embedCode.trim());
              
                var embedDiv = tempDiv.querySelector('div[id^="s_lc_tdh_"]');
                var scriptElements = tempDiv.querySelectorAll("script");
              
                if (embedDiv) {
                  // Clear any existing content
                  container.innerHTML = '';
                  
                  // Clone the embed div to ensure unique IDs
                  const clonedEmbedDiv = embedDiv.cloneNode(true);
                  container.appendChild(clonedEmbedDiv);
              
                  // Process inline scripts
                  scriptElements.forEach((script) => {
                    if (!script.src) {
                      try {
                        const scriptContent = script.textContent.replace(/\$\(/g, "jQuery(");
                        const newScript = document.createElement("script");
                        newScript.type = "text/javascript";
                        newScript.textContent = `
                          (function($){
                            $(document).ready(function() {
                              ${scriptContent}
                            });
                          })(jQuery);
                        `;
                        
                        document.body.appendChild(newScript);
                      } catch (e) {
                        console.error("🚨 Error injecting inline script:", e);
                      }
                    }
                  });
                } else {
                  console.error('Dynamic div with id "s_lc_tdh_" not found in embed code.');
                }
              }

              function reformatDatesAndTimes(container) {
                const dateElements = container.querySelectorAll(".s-lc-w-head-pre + span");
                const timeElements = container.querySelectorAll(".s-lc-w-time");

                if (container && !container.style.minHeight) {
                  const currentHeight = container.offsetHeight;
                  container.style.minHeight = currentHeight + "px";
                }

                dateElements.forEach((el) => {
                  let dateText = el.textContent.trim();
                  dateText = dateText.replace(/^\w+,\s*/, "");
                  dateText = dateText.replace(/\b(January|February|March|April|May|June|July|August|September|October|November|December)\b/g, 
                    (month) => month.substring(0, 3)
                  );
                  el.textContent = dateText;
                });

                timeElements.forEach((el) => {
                  let timeText = el.textContent.trim();
                  timeText = timeText.replace(/:00/g, "")
                    .replace(/am/g, ' <span class="am">a.m.</span>')
                    .replace(/pm/g, ' <span class="pm">p.m.</span>');
                  el.innerHTML = timeText;
                });

                container.querySelectorAll("a").forEach((link) => {
                  link.addEventListener("click", (event) => {
                    event.preventDefault();
                    console.log('link stopped');
                  });
                });
              }

              function monitorEmbedDiv(container) {
                const observer = new MutationObserver(() => {
                  if (container.innerHTML.trim() !== "") {
                    observer.disconnect();
                    waitForLibCal(() => {
                      reformatDatesAndTimes(container);
                      monitorAjaxButtons(container);
                    });
                  }
                });

                observer.observe(container, { childList: true, subtree: true });
              }

              function monitorAjaxButtons(container) {
                container.querySelectorAll(".s-lc-w-btn").forEach((button) => {
                  button.addEventListener("click", function () {
                    monitorEmbedDiv(container);
                  });
                });
              }

              injectEmbedCode(embedCode);
              monitorEmbedDiv(container);
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