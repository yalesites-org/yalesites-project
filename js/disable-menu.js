Drupal.behaviors.disableMenu = {
  attach: function (context, settings) {
    const utilityMenuElement = document.querySelectorAll('.utility-nav__item');
    const primaryMenuElement = document.querySelectorAll('.primary-nav__item');
    const hamburgerMenuElement = document.querySelector('.menu-toggle');

    if (utilityMenuElement.length === 0 && primaryMenuElement.length === 0) {
      hamburgerMenuElement.style.display = 'none';
    }
  }
}
