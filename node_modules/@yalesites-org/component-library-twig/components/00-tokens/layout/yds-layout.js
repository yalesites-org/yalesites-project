Drupal.behaviors.layout = {
  attach(context) {
    // Classes.
    // This array of classes should not have the preceding `.` so that we can
    // check for them in `classList.contains` below.
    const classesToCheck = ['text-field', 'wrapped-image'];
    // Generate a string of the above classes with preceding `.` for the
    // querySelectorAll below.
    const bodyCopyClasses = classesToCheck.map((i) => `.${i}`);
    // Selectors.
    const bodyCopyComponents = context.querySelectorAll(bodyCopyClasses);

    bodyCopyComponents.forEach((component) => {
      const nextElement = component.nextElementSibling;

      if (
        // If there is a next element.
        nextElement &&
        // And the next element contains one of the classesToCheck
        classesToCheck.some((className) =>
          nextElement.classList.contains(className),
        )
      ) {
        // Add the `no-page-spacing` class to the component.
        component.classList.add('no-page-spacing');
      }
    });
  },
};
