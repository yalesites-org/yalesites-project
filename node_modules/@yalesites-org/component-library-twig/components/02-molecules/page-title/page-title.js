Drupal.behaviors.bannerHeading = {
  attach(context) {
    // Find the body element
    const bodyElement = context.querySelector('body');
    const pageTitle = context.querySelector('.page-title');

    // If there is no page title or the page title is present but not visible, add an attribute to the body element
    if (pageTitle === null || !pageTitle.classList.contains('visible')) {
      bodyElement.setAttribute('page-title-hidden', 'true');
    }
  },
};
