Drupal.behaviors.toggleLinks = {
  attach(context) {
    // Get the elements
    const showMoreDatesWrapper = context.querySelector(
      '.event-meta__all-dates',
    );
    const showMoreDatesButton = context.querySelector(
      '.event-meta__cta--show-more-dates',
    );
    const showMapWrapper = context.querySelector('.event-meta__event-show-map');
    const showMapButton = context.querySelector('.event-meta__cta--show-map');
    const mapElementWrapper = context.querySelector('.event-meta__map');

    // Function to toggle aria-expanded attribute
    const toggleAriaExpanded = (element) => {
      const currentAriaExpanded =
        element.getAttribute('aria-expanded') === 'true';
      element.setAttribute('aria-expanded', String(!currentAriaExpanded));
    };

    // Function to toggle is-expanded class
    const toggleIsExpanded = (element) => {
      const currentIsExpanded = element.getAttribute('is-expanded') === 'true';
      element.setAttribute('is-expanded', String(!currentIsExpanded));
    };

    // Handle Show More Dates button click
    function handleShowMoreDatesClick(event) {
      event.preventDefault(); // or return false;
      const button = event.target;
      toggleAriaExpanded(showMoreDatesButton);
      toggleIsExpanded(showMoreDatesWrapper);

      const targetDiv = button.closest(
        '.event-meta__more-dates-link',
      ).nextElementSibling;
      if (
        targetDiv &&
        targetDiv.classList.contains('event-meta__multiple-dates') &&
        targetDiv.hasAttribute('aria-expanded')
      ) {
        toggleAriaExpanded(targetDiv);
      }
    }

    // Handle Show Map button click
    function handleShowMapClick(event) {
      event.preventDefault(); // or return false;
      toggleAriaExpanded(showMapButton);
      toggleIsExpanded(showMapWrapper);
      toggleAriaExpanded(mapElementWrapper);
    }

    // Use the buttons
    if (showMoreDatesButton) {
      showMoreDatesButton.setAttribute('aria-expanded', 'false');
      showMoreDatesButton.addEventListener('click', handleShowMoreDatesClick);
    }

    if (showMapButton) {
      showMapButton.setAttribute('aria-expanded', 'false');
      showMapButton.addEventListener('click', handleShowMapClick);
      mapElementWrapper.setAttribute('aria-expanded', 'false');
    }
  },
};
