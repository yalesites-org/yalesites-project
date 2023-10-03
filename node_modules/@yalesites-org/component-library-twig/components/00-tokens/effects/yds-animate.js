Drupal.behaviors.animateItems = {
  attach(context) {
    // Check if animation is active in site settings.
    const siteAnimationTheme = context.querySelector('[data-site-animation]');

    // Set variable to check that the animation theme isn't the default.
    const siteAnimationEnabled =
      siteAnimationTheme.getAttribute('data-site-animation') !== 'default';

    // Select all elements with [data-animate-item] attribute
    const elementsToAnimate = context.querySelectorAll(
      '[data-animate-item="enabled"]',
    );

    // Check if the user prefers reduced motion
    const prefersReducedMotionNoPref = window.matchMedia(
      '(prefers-reduced-motion: no-preference)',
    ).matches;

    // Create a new Intersection Observer
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        const animatedElement = entry.target;

        if (entry.isIntersecting) {
          // If the element is in the viewport, add the 'animate' class
          animatedElement.classList.add('animate');
        }
      });
    });
    // Only add observer if siteAnimationEnabled, there are elements to animate,
    // and if user hasn't enabled reduced motion.
    if (
      elementsToAnimate &&
      siteAnimationEnabled &&
      prefersReducedMotionNoPref
    ) {
      // Observe each .divider element
      elementsToAnimate.forEach((animatedElement) => {
        observer.observe(animatedElement);
      });
    }
    // Set each component to data-animate-item false if prefers reduced motion.
    if (!prefersReducedMotionNoPref) {
      elementsToAnimate.forEach((reducedMotionElement) => {
        reducedMotionElement.setAttribute('data-animate-item', 'disabled');
      });
    }
  },
};
