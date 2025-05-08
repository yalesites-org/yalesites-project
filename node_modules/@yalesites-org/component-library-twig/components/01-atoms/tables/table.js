Drupal.behaviors.table = {
  attach(context) {
    // Selectors
    const items = context.querySelectorAll(
      '[data-component-theme] .table-wrapper',
    );

    const filtered = Array.from(items).filter((el) => {
      const theme = el
        .closest('[data-component-theme]')
        ?.getAttribute('data-component-theme');
      return theme !== 'default';
    });

    function darkenRgb(rgbString, factor) {
      const rgbMatch = rgbString.match(/\d+/g);
      if (!rgbMatch) return rgbString;

      const [r, g, b] = rgbMatch.map(Number);
      const darken = (val) => Math.max(0, Math.floor(val * factor));

      return `rgb(${darken(r)}, ${darken(g)}, ${darken(b)})`;
    }

    filtered.forEach((item) => {
      const layout = item.closest('[data-component-theme]');
      const styles = window.getComputedStyle(layout);
      const table = item.querySelector('table');
      const bg = styles.backgroundColor;

      const { componentTheme } = layout.dataset;

      if (componentTheme !== 'two') {
        table.style.setProperty('--header-cell-bg', darkenRgb(bg, 0.6));
        table.style.setProperty('--header-row-bg', darkenRgb(bg, 0.7));
      } else {
        table.style.setProperty('--header-cell-bg', darkenRgb(bg, 0.9));
        table.style.setProperty('--header-row-bg', darkenRgb(bg, 0.9));
      }
    });
  },
};
