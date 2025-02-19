(function () {

    function waitForJQuery(callback) {
        if (typeof window.jQuery !== "undefined" && typeof window.Drupal !== "undefined") {
          window.jQuery = window.jQuery || Drupal.jQuery;
          window.$ = window.jQuery; // ✅ Ensure $ is available globally
          callback(window.jQuery, window.Drupal);
        } else {
          console.warn("jQuery or Drupal not found. Retrying...");
          setTimeout(() => waitForJQuery(callback), 100);
        }
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
        let libcalInitialized = false;
  
        Drupal.behaviors.libcalEmbed = {
          attach: function (context, settings) {
            if (libcalInitialized) {
              return;
            }
            libcalInitialized = true;
            let embedCode = settings.ysEmbed?.libcalEmbedCode || '';
  
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
                  document.querySelector(".embed-libcal").appendChild(embedDiv);
              
                  scriptElements.forEach(function (script) {
                    var newScript = document.createElement("script");
              
                    if (script.src) {
                      if (!document.querySelector(`script[src="${script.src}"]`)) {
                        newScript.src = script.src;
                        newScript.type = script.type || "text/javascript";
                        newScript.onload = function () {
              
                          waitForLibCal(() => {
                            executeDelayedLibCalScripts(tempDiv);
                          });
                        };
                        document.body.appendChild(newScript);
                      }
                    } else {
                      newScript.type = "text/javascript";
                      newScript.text = script.textContent;
                      newScript.dataset.defer = "true";
                    }
                  });
                } else {
                  console.error('Dynamic div with id "s_lc_tdh_" not found in embed code.');
                }
            }
  
            function executeDelayedLibCalScripts(container) {
                const inlineScripts = container.querySelectorAll("script");
            
                inlineScripts.forEach((script) => {
                    if (!script.src) {
            
                        waitForLibCal(($) => {
                            
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
                        });
                    }
                });
            }
  
            function reformatDatesAndTimes() {
              const dateElements = document.querySelectorAll(".s-lc-w-head-pre + span");
              const timeElements = document.querySelectorAll(".s-lc-w-time");
  
              const embedLibcal = document.querySelector(".embed-libcal");
              if (embedLibcal && !embedLibcal.style.minHeight) {
                const currentHeight = embedLibcal.offsetHeight;
                embedLibcal.style.minHeight = currentHeight + "px";
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
  
              document.querySelectorAll(".embed-libcal a").forEach((link) => {
                link.addEventListener("click", (event) => {
                  event.preventDefault();
                });
              });
            }
  
            function monitorEmbedDiv() {
              const embedDiv = document.querySelector(".embed-libcal");
  
              if (!embedDiv) {
                return;
              }
  
              const observer = new MutationObserver(() => {
                if (embedDiv.innerHTML.trim() !== "") {
                  observer.disconnect();
                  waitForLibCal(() => {
                    reformatDatesAndTimes();
                    monitorAjaxButtons();
                  });
                }
              });
  
              observer.observe(embedDiv, { childList: true, subtree: true });
            }
  
            function monitorAjaxButtons() {
              document.querySelectorAll(".s-lc-w-btn").forEach((button) => {
                button.addEventListener("click", function () {
                  monitorEmbedDiv();
                });
              });
            }
  
            injectEmbedCode(embedCode);
            monitorEmbedDiv();
          },
        };
  
        $(document).ready(function () {
          Drupal.attachBehaviors(document, Drupal.settings);
        });
  
      })(jQuery, Drupal);
    });
  })();