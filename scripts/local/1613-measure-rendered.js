/**
 * Reads the RESOLVED colors out of a rendered #1613 fixture page.
 *
 * Companion to `section-background-contrast.mjs` in component-library-twig,
 * which computes what the palette permits. This reads what the cascade
 * actually produced, which is the only thing that catches a component whose
 * custom property never resolves (see #1614: Link Grid's --color-slot-seven
 * resolved only by inheriting from an ancestor).
 *
 * Evaluated in the page by playwright-cli; returns JSON on stdout.
 */
(() => {
  const sections = [...document.querySelectorAll('.yds-layout[data-component-theme]')];

  // A transparent background means "whatever is painted behind me"; walk up
  // until something actually paints, or we reach the document.
  const paintedBackground = (start) => {
    let el = start;
    while (el) {
      const bg = getComputedStyle(el).backgroundColor;
      if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') return bg;
      el = el.parentElement;
    }
    return 'rgb(255, 255, 255)';
  };

  return sections.map((section) => {
    const heading = section.querySelector('h2');
    const body = section.querySelector('p');
    const link = section.querySelector('p a');
    const sidebar = section.querySelector('.yds-layout__secondary');

    return {
      sectionTheme: section.getAttribute('data-component-theme'),
      layout: section.getAttribute('data-component-layout') || 'onecol',
      background: paintedBackground(section),
      heading: heading ? getComputedStyle(heading).color : null,
      body: body ? getComputedStyle(body).color : null,
      link: link ? getComputedStyle(link).color : null,
      // The 70/30 column separator. _yds-layout.scss sets this from
      // --color-divider rather than from --color-layout-border, so unlike the
      // opt-in .yds-layout__divider it is not section-theme aware.
      sidebarBorder: sidebar
        ? getComputedStyle(sidebar).borderLeftColor
        : null,
    };
  });
})();
