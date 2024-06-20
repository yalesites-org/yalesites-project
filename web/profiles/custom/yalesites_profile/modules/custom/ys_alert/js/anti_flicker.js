/**
 * @file
 * Prevents flicker of dismissed alerts on page load.
 */

(() => {

    // Function to get all alerts from localstorage.
    const setHtmlAlertStyle = () => {
        let styleContent = '';
        Object.keys(localStorage).forEach((key) => {
            if (key.substring(0, 12) === 'ys-alert-id-') {
                if (localStorage.getItem(key) === 'dismissed') {
                   styleContent += `
                      .alert[data-alert-id=${key}] {
                          visibility: hidden;
                          opacity: 0;
                          max-height: 0;
                      }`;
                }
            }
        });
        // If we have dismissed alerts, add style to hide them.
        if (styleContent.length > 0) {
            const alertStyle = document.createElement('style');
            alertStyle.textContent = styleContent;
            document.querySelector('head').appendChild(alertStyle);
        }
    };

    setHtmlAlertStyle();

})();
