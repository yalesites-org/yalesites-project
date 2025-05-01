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
    showTaxonomy: {
      name: 'Show Taxonomy',
      type: 'boolean',
    },
  },
  args: {
    componentTheme: 'default',
    showTaxonomy: true,
  },
};

export const TaxonomyDisplay = ({ componentTheme, showTaxonomy }) =>
  taxonomyDisplayTwig({
    taxonomy_display__theme: componentTheme,
    taxonomy_display__items: showTaxonomy
      ? taxonomyDisplayData.taxonomy_display__items
      : taxonomyDisplayData.taxonomy_display__empty_items,
  });
