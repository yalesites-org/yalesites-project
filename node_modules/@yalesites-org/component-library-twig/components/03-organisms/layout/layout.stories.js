// Markup.
import twoColumnTwig from './two-column/_two-column--example.twig';

// Data files
import textData from '../../02-molecules/text/text-field.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Layout/Two Column',
  parameters: {
    layout: 'fullscreen',
  },
};

export const TwoColumn = () => twoColumnTwig(textData);
