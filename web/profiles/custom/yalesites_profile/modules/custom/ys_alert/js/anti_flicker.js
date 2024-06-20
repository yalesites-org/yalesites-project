/**
 * @file
 * Prevents flicker of the toolbar on page load.
 */

(() => {

    // Function to get all alerts from localstorage.
    const setHtmlAlertStyle = () => {
        const alertStyle = document.createElement('style');
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
        alertStyle.textContent = styleContent;
        document.querySelector('head').appendChild(alertStyle);
    };

    setHtmlAlertStyle();

})();
