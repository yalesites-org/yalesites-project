import tokens from '@yalesites-org/tokens/build/json/tokens.json';

// Twig templates
import taxonomyDisplayTwig from './yds-taxonomy-display.twig';

// Data files
import taxonomyDisplayData from './taxonomy-display.yml';

const colorPairingsData = Object.keys(tokens['component-themes']);

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Taxonomy Display',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    componentTheme: {
      name: 'Component Theme (dial)',
      type: 'select',
      options: colorPairingsData,
    },
  },
  args: {
    componentTheme: 'default',
  },
};

export const TaxonomyDisplay = ({ componentTheme }) =>
  taxonomyDisplayTwig({
    taxonomy_display__theme: componentTheme,
    taxonomy_display__items: taxonomyDisplayData.taxonomy_display__items,
  });
