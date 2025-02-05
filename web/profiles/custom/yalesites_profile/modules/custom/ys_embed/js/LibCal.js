(function () {

    function waitForJQuery(callback) {
        if (typeof window.jQuery !== "undefined") {
          window.jQuery = window.jQuery || Drupal.jQuery;
          window.$ = window.jQuery; // ✅ Force $ to be globally available
          callback(window.jQuery);
        } else {
          console.warn("jQuery not found. Retrying...");
          setTimeout(() => waitForJQuery(callback), 100);
        }
      }
  
    function waitForLibCal(callback) {
      if (typeof jQuery !== "undefined" && typeof jQuery.LibCalTodayHours !== "undefined") {
        callback(jQuery);
      } else {
        console.warn("LibCalTodayHours not found. Retrying...");
        setTimeout(() => waitForLibCal(callback), 100);
      }
    }
  
    waitForJQuery(function ($) {
      console.log("jQuery loaded:", $.fn.jquery);
  
      (function ($, Drupal) {
        let libcalInitialized = false;
  
        Drupal.behaviors.libcalEmbed = {
          attach: function (context, settings) {
            if (libcalInitialized) {
              return;
            }
            libcalInitialized = true;
            console.log("LibCal JavaScript loaded");
  
            let embedCode = settings.ysEmbed?.libcalEmbedCode || '';
            console.log("Embed Code Received:", embedCode);
  
            function injectEmbedCode(embedCode) {
                function decodeHtmlEntities(html) {
                  var textArea = document.createElement("textarea");
                  textArea.innerHTML = html;
                  return textArea.value;
                }
              
                var tempDiv = document.createElement("div");
                tempDiv.innerHTML = decodeHtmlEntities(embedCode.trim());
                console.log(tempDiv.innerHTML);
              
                var embedDiv = tempDiv.querySelector('div[id^="s_lc_tdh_"]');
                var scriptElements = tempDiv.querySelectorAll("script");
              
                if (embedDiv) {
                  document.querySelector(".embed-libcal").appendChild(embedDiv);
              
                  scriptElements.forEach(function (script) {
                    var newScript = document.createElement("script");
              
                    if (script.src) {
                      // ✅ Load external script (hours_today.js) and wait for it to finish
                      newScript.src = script.src;
                      newScript.type = script.type || "text/javascript";
                      newScript.onload = function () {
                        console.log("hours_today.js script loaded.");
                        waitForLibCal(() => {
                          console.log("Executing delayed LibCal initialization...");
                          executeDelayedLibCalScripts(tempDiv);
                        });
                      };
                    } else {
                      // ✅ Store inline scripts for later execution (after hours_today.js loads)
                      newScript.type = "text/javascript";
                      newScript.text = script.textContent;
                      newScript.dataset.defer = "true"; // Mark for delayed execution
                    }
              
                    document.querySelector(".embed-libcal").appendChild(newScript);
                  });
                } else {
                  console.error('Dynamic div with id "s_lc_tdh_" not found in embed code.');
                }
            }
  
            function executeDelayedLibCalScripts(container) {
                const inlineScripts = container.querySelectorAll("script[data-defer='true']");
                
                inlineScripts.forEach((script) => {
                  waitForJQuery(($) => {
                    console.log("Executing delayed inline LibCal script with jQuery.");
                    const newScript = document.createElement("script");
                    newScript.type = "text/javascript";
                    newScript.text = script.textContent
                      .replace(/\$\(/g, "jQuery(")  // ✅ Replace all instances of $() with jQuery()
                      .replace(/\b\$\./g, "jQuery."); // ✅ Ensure $.LibCalTodayHours is correctly referenced
                    document.body.appendChild(newScript);
                  });
                });
              }
  
            function reformatDatesAndTimes() {
              console.log("Reformatting dates and times...");
              const dateElements = document.querySelectorAll(".s-lc-w-head-pre + span");
              const timeElements = document.querySelectorAll(".s-lc-w-time");
  
              const embedLibcal = document.querySelector(".embed-libcal");
              if (embedLibcal && !embedLibcal.style.minHeight) {
                const currentHeight = embedLibcal.offsetHeight;
                embedLibcal.style.minHeight = currentHeight + "px";
              }
  
              dateElements.forEach((el) => {
                console.log("Reformatting dates");
                let dateText = el.textContent.trim();
                dateText = dateText.replace(/^\w+,\s*/, "");
                dateText = dateText.replace(/\b(January|February|March|April|May|June|July|August|September|October|November|December)\b/g, 
                  (month) => month.substring(0, 3)
                );
                el.textContent = dateText;
              });
  
              timeElements.forEach((el) => {
                console.log("Reformatting times");
                let timeText = el.textContent.trim();
                timeText = timeText.replace(/:00/g, "")
                  .replace(/am/g, ' <span class="am">a.m.</span>')
                  .replace(/pm/g, ' <span class="pm">p.m.</span>');
                el.innerHTML = timeText;
              });
  
              document.querySelectorAll(".embed-libcal a").forEach((link) => {
                link.addEventListener("click", (event) => {
                  event.preventDefault();
                  console.log("Link click prevented.");
                });
              });
            }
  
            function monitorEmbedDiv() {
              const embedDiv = document.querySelector(".embed-libcal");
  
              if (!embedDiv) {
                console.error("No embed-libcal found in code.");
                return;
              }
  
              const observer = new MutationObserver(() => {
                if (embedDiv.innerHTML.trim() !== "") {
                  console.log("LibCal embed output detected");
                  observer.disconnect();
                  waitForLibCal(() => {
                    console.log("Running reformatDatesAndTimes after LibCal is ready.");
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
                  console.log("Button clicked, waiting for AJAX update...");
                  monitorEmbedDiv();
                });
              });
            }
  
            injectEmbedCode(embedCode);
            monitorEmbedDiv();
          },
        };
  
        $(document).ready(function () {
          console.log("Triggering Drupal behaviors");
          Drupal.attachBehaviors(document, Drupal.settings);
        });
  
      })(jQuery, Drupal);
    });
  })();