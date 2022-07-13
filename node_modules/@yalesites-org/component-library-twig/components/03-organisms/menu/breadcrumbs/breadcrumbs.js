Drupal.behaviors.breadcrumbs = {
  attach(context) {
    // Selectors.
    const breadcrumbsWrapper = context.querySelector('.breadcrumbs__wrapper');
    const breadcrumbsButton = context.querySelector('.breadcrumbs__button');

    // Function to add/remove breadcrumbs-overflow value.
    // Used to show all breadcrumbs on mobile
    if (breadcrumbsButton) {
      breadcrumbsButton.addEventListener('click', () => {
        breadcrumbsWrapper.setAttribute('data-breadcrumbs-overflow', 'visible');
        breadcrumbsButton.setAttribute('aria-expanded', 'true');
      });
    }
  },
};
