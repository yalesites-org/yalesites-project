// This js is strictly for Storybook to simulate pagination.

Drupal.behaviors.clPagination = {
  attach(context) {
    const items = context.querySelectorAll('.pager__item');
    const activeClass = 'is-active';

    items.forEach((item) => {
      item.addEventListener('click', (e) => {
        e.preventDefault();

        const activeItem = context.querySelector('.is-active');
        const activeLink = activeItem.querySelector('.pager__link');
        const link = item.querySelector('a');

        // Remove active class from previously active item.
        activeItem.classList.remove(activeClass);
        activeLink.classList.remove(activeClass);
        // Simulate the item becoming a link.
        activeLink.style.cursor = 'pointer';
        // Add active class to the clicked item.
        item.classList.add(activeClass);
        link.classList.add(activeClass);
        // Simulate the item becoming plain text.
        link.style.cursor = 'text';
      });
    });
  },
};
