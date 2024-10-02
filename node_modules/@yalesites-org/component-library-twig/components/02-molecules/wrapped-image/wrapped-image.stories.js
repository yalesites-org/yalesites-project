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
    imageAlignment: {
      name: 'Image Alignment',
      type: 'select',
      options: ['left', 'right'],
      defaultValue: 'left',
    },
    imageStyle: {
      name: 'Image Style',
      type: 'select',
      options: ['floated', 'offset'],
      defaultValue: 'floated',
    },
  },
};

export const WrappedImage = ({ caption, imageAlignment, imageStyle }) => `
  ${textFieldTwig({
    text_field__content: WrappedImageData.text_one,
    text_field__width: 'site',
    text_field__alignment: 'left',
  })}
  ${wrappedImageTwig({
    ...imageData.responsive_images['3x2'],
    wrapped_image__caption: caption,
    wrapped_image__alignment: imageAlignment,
    wrapped_image__style: imageStyle,
    wrapped_image__content: WrappedImageData.text_two,
  })}
`;
