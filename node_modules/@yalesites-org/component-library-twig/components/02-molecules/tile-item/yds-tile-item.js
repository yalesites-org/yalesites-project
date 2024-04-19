Drupal.behaviors.tileItem = {
  attach(context) {
    // Inspiration and reasoning for this JavaScript can be found in the Cards
    // chapter of the book linked below:
    // https://inclusive-components.design/cards/#theredundantclickevent
    // Selectors
    const tileItems = context.querySelectorAll('.tile__item');

    tileItems.forEach((tileItem) => {
      const tile = tileItem;
      const link = tile.querySelector('.tile__item__link');

      // If the tile has a link, make the whole tile clickable. However, allow
      // users to select text by only triggering the link if the "click up" is
      // less than 200ms from the "click down".
      if (link) {
        let down;
        let up;

        tile.style.cursor = 'pointer';
        tile.onmousedown = () => {
          // Calculate when the "click" starts.
          down = +new Date();
        };
        tile.onmouseup = () => {
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
