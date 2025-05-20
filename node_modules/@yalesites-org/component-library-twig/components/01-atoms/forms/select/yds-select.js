(($) => {
  const filterForm = '.ys-filter-form--scaffold';
  const selectMessageClass = 'ys-select-message';

  Drupal.behaviors.chosenSelect = {
    attach(context) {
      const ysChosenReady = (e) => {
        $(e.target)
          .next()
          .find('.chosen-choices')
          .prepend(`<span class=${selectMessageClass}></span>`);
      };
      const ysSelectChange = (e) => {
        const selectedNr = $(e.target).val().length;
        const selectMessage = selectedNr
          ? `${`(${selectedNr}) items selected`}`
          : '';

        $(e.target).next().find(`.${selectMessageClass}`).text(selectMessage);
      };
      $(once('ys-chosen-select', filterForm, context)).each((i, elem) => {
        const $select = $(elem).find('select');
        $select.on('chosen:ready', ysChosenReady);
        $select.on('change', ysSelectChange);
      });
    },
  };
})(jQuery);
