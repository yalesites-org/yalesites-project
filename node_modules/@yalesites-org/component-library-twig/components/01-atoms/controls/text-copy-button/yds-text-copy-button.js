Drupal.behaviors.textCopyButton = {
  attach(context) {
    // Add click event listener for clicking the text
    const elems = context.querySelectorAll('.text-copy-button__button');
    elems.forEach((elem) => {
      elem.addEventListener(
        'click',
        (event) => {
          // Only fire if the target has id copy
          if (!event.target.matches('.text-copy-button__button')) return;

          if (!navigator.clipboard) {
            // Clipboard API not available
            return;
          }
          const text = event.target.parentNode
            .querySelector('.pre-text__text')
            .textContent.trim();
          try {
            navigator.clipboard.writeText(text);
            const triggerValue = elem;
            triggerValue.innerHTML = 'Copied to clipboard';
            setTimeout(() => {
              triggerValue.innerHTML = '(Copy)';
            }, 1200);
          } catch (error) {
            const triggerValue = elem;
            triggerValue.innerHTML = '(error)';
          }
        },
        false,
      );
    });
  },
};
