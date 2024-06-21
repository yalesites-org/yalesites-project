// Twig templates
import textFieldTwig from './yds-text-field.twig';

// Data files
import textData from './text-field.yml';

import '../../01-atoms/typography/text/yds-text';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Text',
  argTypes: {
    variation: {
      name: 'Text Field Variation',
      options: ['default', 'emphasized'],
      type: 'select',
      defaultValue: 'default',
    },
  },
};

export const TextField = ({ variation }) => `
${textFieldTwig({
  text_field__content: textData.text_field__content,
  text_field__variation: variation,
})}
`;
