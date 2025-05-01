import tokens from '@yalesites-org/tokens/build/json/tokens.json';

// Markup.
import secondaryNavTwig from './yds-secondary-nav.twig';

// Data.
import secondaryNavData from './secondary-nav.yml';

// JavaScript
import './yds-secondary-nav';

const colorPairingsData = Object.keys(tokens['component-themes']);

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Menu/Secondary Nav',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    themeColor: {
      name: 'Component Theme (dial)',
      options: colorPairingsData,
      type: 'select',
    },
  },
  args: {
    themeColor: 'one',
  },
};

export const secondaryNav = ({ themeColor }) => `
  <div style="position: relative; padding-top: var(--size-spacing-site-gutter);" data-component-width="max" data-component-theme="${themeColor}">
    ${secondaryNavTwig({ ...secondaryNavData, menu_theme: themeColor })}
  </div>
`;
