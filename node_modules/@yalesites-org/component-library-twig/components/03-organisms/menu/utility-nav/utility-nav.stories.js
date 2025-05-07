import tokens from '@yalesites-org/tokens/build/json/tokens.json';

// Markup.
import utilityNavTwig from './yds-utility-nav.twig';
import utilityNavExampleTwig from './yds-utility-nav--example.twig';

// Data.
import utilityNavData from './utility-nav.yml';

import './utility-nav-dropdown-menu';

const themes = Object.keys(tokens['site-header-themes']);

/**
 * Storybook Definition.
 */
export default { title: 'Organisms/Menu/Utility Nav' };

export const UtilityNav = () => utilityNavTwig(utilityNavData);

export const UtilityNavExamples = () =>
  `<div class="utility-nav--examples">
    ${themes
      .map((theme) =>
        utilityNavExampleTwig({
          site_header__theme: theme,
        }),
      )
      .join('')}
  </div>`;
