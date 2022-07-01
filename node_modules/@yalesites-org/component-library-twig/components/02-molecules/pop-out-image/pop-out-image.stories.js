import popOutImageTwig from './pop-out-image.twig';
import textFieldTwig from '../text/text-field.twig';

import imageData from '../../01-atoms/images/image/image.yml';
import popOutImageData from './pop-out-image.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Pop Out Image',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    caption: {
      name: 'Caption',
      type: 'string',
      defaultValue: 'This is the caption for the 16:9 image above.',
    },
  },
};

export const PopOutImage = ({ caption }) => `
  ${textFieldTwig({
    text_field__content: popOutImageData.text_one,
  })}
  ${popOutImageTwig({
    ...imageData.responsive_images['3x2'],
    pop_out_image__caption: caption,
    pop_out_image__content: popOutImageData.text_two,
  })}
`;
