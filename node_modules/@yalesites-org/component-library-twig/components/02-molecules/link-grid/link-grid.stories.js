import tokens from '@yalesites-org/tokens/build/json/tokens.json';

import linkGridTwig from './yds-link-grid.twig';

import linkGridData from './link-grid.yml';

const colorPairingsData = Object.keys(tokens['component-themes']);

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Link grid',
  argTypes: {
    themeColor: {
      name: 'Component Theme (dial)',
      type: 'select',
      options: colorPairingsData,
      defaultValue: 'one',
    },
  },
};

export const linkGrid = ({ themeColor }) =>
  linkGridTwig({
    link_grid__theme: themeColor,
    ...linkGridData,
  });
