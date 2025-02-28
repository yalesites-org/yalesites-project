import tokens from '@yalesites-org/tokens/build/json/tokens.json';

// Markup.
import primaryNavTwig from './yds-primary-nav.twig';

// Data.
import primaryNavData from './primary-nav.yml';

// JavaScript
import './yds-primary-nav';

const siteHeaderThemeOptions = Object.keys(tokens['site-header-themes']);

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Menu/Primary Nav',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    siteHeaderTheme: {
      name: 'Site Header Theme',
      options: siteHeaderThemeOptions,
      type: 'select',
    },
    menuVariation: {
      name: 'Menu Variation',
      options: ['basic', 'mega', 'focus'],
      type: 'select',
    },
  },
  args: {
    siteHeaderTheme: 'one',
    menuVariation: 'basic',
  },
};

export const PrimaryNav = ({ siteHeaderTheme, menuVariation }) => `
  <div style="position: relative; padding-top: var(--size-spacing-site-gutter);" data-site-header-nav-position='left' data-component-width="max" data-header-theme="${siteHeaderTheme}">
    ${primaryNavTwig({ ...primaryNavData, menu__variation: menuVariation })}
  </div>
`;
