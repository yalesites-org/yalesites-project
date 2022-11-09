import wrappedImageTwig from './yds-wrapped-image.twig';
import textFieldTwig from '../text/yds-text-field.twig';

import imageData from '../../01-atoms/images/image/image.yml';
import WrappedImageData from './wrapped-image.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Wrapped Image',
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

export const WrappedImage = ({ caption }) => `
  ${textFieldTwig({
    text_field__content: WrappedImageData.text_one,
  })}
  ${wrappedImageTwig({
    ...imageData.responsive_images['3x2'],
    wrapped_image__caption: caption,
    wrapped_image__content: WrappedImageData.text_two,
  })}
`;
