<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Instagram post embed source.
 *
 * @EmbedSource(
 *   id = "libcal",
 *   label = @Translation("LibCal"),
 *   description = @Translation("LibCal embed source."),
 *   thumbnail = "",
 *   active = TRUE,
 * )
 */
class LibCal extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/(?<embed_code>\<script src="https:\/\/schedule\.yale\.edu\/.*?<\/script>\s*<div id="([^"]+)"\>\<\/div\>\s*<script>.*?<\/script>(?:\s*<style>.*?<\/style>)?)/s';

  /**
   * {@inheritdoc}
   */
  protected static $template = <<<EOT
<div class="embed-libcal">
<script>
(function() {

    // Function to safely inject the embed code
    function injectEmbedCode(embedCode) {

        function decodeHtmlEntities(html) {
            var textArea = document.createElement('textarea');
            textArea.innerHTML = html;
            return textArea.value;
        }

        var tempDiv = document.createElement('div');
        tempDiv.innerHTML = decodeHtmlEntities(embedCode.trim()); // Decode HTML entities
        console.log(tempDiv.innerHTML);

        var embedDiv = tempDiv.querySelector('div[id^="s_lc_tdh_"]'); // Find the div with the dynamic ID
        var scriptElements = tempDiv.querySelectorAll('script'); // Extract all script elements

        if (embedDiv) {
            document.querySelector('.embed-libcal').appendChild(embedDiv); // Append the div to the wrapper

            scriptElements.forEach(function(script) {
                var newScript = document.createElement('script');
                if (script.src) {
                    // If it's an external script
                    newScript.src = script.src;
                    newScript.type = script.type || 'text/javascript';
                } else {
                    // If it's an inline script
                    newScript.type = 'text/javascript';
                    newScript.text = script.textContent.replace(/\\$\(/g, 'jQuery(') // Replace $ with jQuery
                                                      .replace(/\\$\\.LibCalTodayHours/g, 'jQuery.LibCalTodayHours'); // Replace $. with jQuery.
                }
                document.querySelector('.embed-libcal').appendChild(newScript); // Append the script
            });

        } else {
            console.error('Dynamic div with id "s_lc_tdh_" not found in embed code.');
        }
    }

    // Function to reformat dates and times
    function reformatDatesAndTimes() {
        const dateElements = document.querySelectorAll('.s-lc-w-head-pre + span');
        const timeElements = document.querySelectorAll('.s-lc-w-time');

        // Set min-height of .embed-libcal the first time this runs
        const embedLibcal = document.querySelector('.embed-libcal');
        if (embedLibcal && !embedLibcal.style.minHeight) {
            const currentHeight = embedLibcal.offsetHeight;
            embedLibcal.style.minHeight = currentHeight +`px`;
        }

        // Reformat dates
        dateElements.forEach(el => {
            console.log('Reformatting dates');
            let dateText = el.textContent.trim();
            // Remove weekday and comma
            dateText = dateText.replace(/^\w+,\s*/, '');
            // Trim leading/trailing spaces and truncate month names
            dateText = dateText.replace(/\b(January|February|March|April|May|June|July|August|September|October|November|December)\b/g, month => month.substring(0, 3));
            el.textContent = dateText;
        });

        // Reformat times
        timeElements.forEach(el => {
            console.log('Reformatting times');
            let timeText = el.textContent.trim();
            // Remove :00 and adjust a.m./p.m. format
            timeText = timeText.replace(/:00/g, '')
                               .replace(/am/g, ' <span class="am">a.m.</span>')
                               .replace(/pm/g, ' <span class="pm">p.m.</span>');
            el.innerHTML = timeText;
        });

        // Select all links in the output
        const links = document.querySelectorAll('.embed-libcal a'); // Adjust selector as needed

        // Loop through each link and attach the event listener
        links.forEach(link => {
            link.addEventListener('click', event => {
                event.preventDefault(); // Prevent the default link behavior
            });
        });
    }

    // Monitor embedDiv for changes and apply formatting
    function monitorEmbedDiv() {
        const embedDiv = document.querySelector('.embed-libcal');

        if (!embedDiv) {
            console.error('No embed-libcal found in code.');
            return;
        }

        const observer = new MutationObserver(() => {
            if (embedDiv.innerHTML.trim() !== "") {
                console.log('LibCal embed output detected');
                observer.disconnect(); // Stop observing once the output is detected
                reformatDatesAndTimes(); // Reformat dates and times
                monitorAjaxButtons(); // Add listeners to buttons for AJAX updates
            }
        });

        observer.observe(embedDiv, { childList: true, subtree: true });
    }

    // Hook into AJAX events triggered by buttons
    function monitorAjaxButtons() {
        const buttons = document.querySelectorAll('.s-lc-w-btn'); // Assuming buttons have this class
        buttons.forEach(button => {
            button.addEventListener('click', function() {
                console.log('Button clicked, waiting for AJAX update...');
                monitorEmbedDiv(); // Start monitoring embedDiv for updates after button click
            });
        });
    }

    // Wait for jQuery to be available
    function waitForJQuery(callback) {
        if (typeof jQuery !== 'undefined') {
            callback(jQuery);
        } else {
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof jQuery !== 'undefined') {
                    callback(jQuery);
                } else {
                    console.error('jQuery not loaded. Embed code will not work.'); 
                }
            });
        }
    }

    // Initialize after ensuring jQuery is loaded
    waitForJQuery(function($) {
        jQuery(function() {
            // Use jQuery explicitly
            injectEmbedCode(`{{ embed_code|escape('js') }}`); // Pass the escaped embed code
            monitorEmbedDiv(); // Start monitoring the embedDiv for changes

        });
    });


})();
</script>
</div>

<style>
.embed-libcal caption, 
.s-lc-w-today-view-all, 
.embed-libcal th > span.s-lc-w-head-pre br, 
.embed-libcal a .link-purpose-icon, 
.s-lc-w-time:has(.am ~ .am, .pm ~ .pm) :is(.am, .pm) {
  display: none;
}
.s-lc-w-time :is(.am ~ .am, .pm ~ .pm) {
  display: inline !important;
}

.embed__inner:has(.embed-libcal) {
    margin-block-start: 0 !important;
}

.embed-libcal {
  position: relative;
  display: block;
  width: 100%;
  max-width: 400px;
  font-size: 20px;
  line-height: 1.4em;
}
.embed-libcal table {
  font: unset;
}
.embed-libcal :is(table, thead, tbody, tr) {
  width: 100%;
  display: block;
}
.embed-libcal :is(table, thead, tr, th, td) {
  background: none !important;
  border: none !important;
}
.embed-libcal tr {
    opacity: 0;
    animation: reveal 0.3s forwards;
    animation-delay: 0.4s;
}
@keyframes reveal {
    from { opacity: 0; }
    to { opacity: 1; }
}




.embed-libcal th {
  display: flex;
  flex-flow: row wrap;
  flex-grow: 1;
  align-items: center;
  justify-content: space-between;
  padding: 0 0 1rem !important;
  font-weight: 400;
}
.embed-libcal th::before {
  content: '';
  display: block;
  width: 100%;
  height: 1px;
  border-bottom: solid 1px #00000011;
  position: absolute;
  bottom: 8px;
}
.embed-libcal th > button:nth-of-type(1) {
  order: 2;
}
.embed-libcal th > span {
  order: 3;
}
.embed-libcal th > span.s-lc-w-head-pre {
  font-size: 30px;
  font: var(--font-style-heading-h4-yale-new);
  font-variant-numeric: oldstyle-nums;
  color: #333333;
  margin-block-end: 1.25rem;
  text-align: left;
  order: 1;
  flex: 0 0 100%;
}
.embed-libcal th > span.s-lc-w-head-pre::after {
    content: ' and Info';
}
.embed-libcal th > button:nth-of-type(2), 
.embed-libcal th > button:nth-of-type(1):nth-last-of-type(1) {
  order: 4;
}
.s-lc-w-head:has(button:nth-of-type(1):nth-last-of-type(1)) {
  text-align: center;
}
.s-lc-w-head:has(button:nth-of-type(1):nth-last-of-type(1)) .s-lc-w-head-pre + span {
  width: calc(100% - 72px);
  margin-left: 36px;
  text-align: center;
  font-size: 0;
}
.s-lc-w-head:has(button:nth-of-type(1):nth-last-of-type(1)) .s-lc-w-head-pre + span::after {
  content: 'Today';
  font-size: 20px;
}
.s-lc-w-head:has(button:nth-of-type(1):nth-last-of-type(1)) .s-lc-w-head-pre + span::before {
  content: '←';
  display: inline-block;
  font-size: 1rem;
  border-radius: 1rem;
  background: #00000011;
  line-height: 1.15;
  padding: 2px 10px 3px;
  color: #999;
  position: absolute;
  left: 0;
  bottom: 22px;
  opacity: 0.6;
}



.embed-libcal td {
  padding: 0 !important;
  background: none !important;
}
.embed-libcal thead td {
  border-bottom: none !important;
}
.embed-libcal tbody td {
  display: block;
  width: 100%;
}
.embed-libcal tbody td:nth-child(1) {
  font-weight: 500;
  padding-top: 1rem !important;
}

.embed-libcal a {
  text-decoration: none;
  color: inherit;
}
.embed-libcal button {
  appearance: none;
  border: none;
  border-radius: 1rem;
  background: #00000011;
  cursor: pointer;
}
button.s-lc-w-previous, 
button.s-lc-w-next {
  font-size: 0;
}
button:is(.s-lc-w-previous, .s-lc-w-next):before {
  content: '←';
  font-size: 1rem;
  padding: 0.25rem;
}
button:is(.s-lc-w-next):before {
  content: '→';
}
</style>
EOT;

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'Place embed code for a LibCal listing. DO NOT INCLUDE JQUERY (make sure that option is unchecked in LibCal). Styles will be ignored.';

  /**
   * {@inheritdoc}
   */
  protected static $example = '<div id="api_hours_today_iid457_lid4211"></div><script src="https://schedule.yale.edu/api_hours_today.php?iid=457&lid=4211&format=js&systemTime=0&context=object"> </script>';

}
