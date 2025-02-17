Drupal.behaviors.referenceCard = {
  attach(context) {
    // Inspiration and reasoning for this JavaScript can be found in the Cards
    // chapter of the book linked below:
    // https://inclusive-components.design/cards/#theredundantclickevent
    // Selectors
    const referenceCards = context.querySelectorAll('.reference-card');

    referenceCards.forEach((referenceCard) => {
      const card = referenceCard;
      const link = card.querySelector('.reference-card__heading-link');

      // If the card has a link, make the whole card clickable. However, allow
      // users to select text by only triggering the link if the "click up" is
      // less than 200ms from the "click down".
      if (link) {
        let down;
        let up;

        card.style.cursor = 'pointer';
        card.onmousedown = () => {
          // Calculate when the "click" starts.
          down = +new Date();
        };
        card.onmouseup = () => {
          // Calculate when the "click" ends.
          up = +new Date();
          // If the click "duration" is less than 200ms, trigger a click.
          if (up - down < 200) {
            link.click();
          }
        };
      }
    });
  },
};
