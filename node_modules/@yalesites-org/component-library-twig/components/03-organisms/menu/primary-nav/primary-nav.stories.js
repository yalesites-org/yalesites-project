import tokens from '@yalesites-org/tokens/build/json/tokens.json';

// Markup.
import primaryNavTwig from './primary-nav.twig';

// Data.
import primaryNavData from './primary-nav.yml';

// JavaScript
import './primary-nav';

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
      defaultValue: 'white',
    },
  },
};

export const PrimaryNav = ({ siteHeaderTheme }) => `
  <div style="position: relative; padding-top: var(--size-spacing-site-gutter);" data-site-header-nav-position='left' data-component-width="max" data-component-theme="${siteHeaderTheme}">
    ${primaryNavTwig(primaryNavData)}
  </div>
`;
