// Twig templates
import textWithImageTwig from './yds-text-with-image.twig';

// Data files
import imageData from '../../01-atoms/images/image/image.yml';
import textWithImageData from './text-with-image.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Text With Image',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    width: {
      name: 'Width',
      type: 'select',
      options: ['highlight', 'site'],
      defaultValue: 'site',
    },
    position: {
      name: 'Image Position',
      type: 'select',
      options: ['image-left', 'image-right'],
      defaultValue: 'image-left',
    },
    focus: {
      name: 'Focus',
      type: 'select',
      options: ['image', 'equal'],
      defaultValue: 'equal',
    },
    overline: {
      name: 'Overline (optional)',
      type: 'string',
      defaultValue: null,
    },
    heading: {
      name: 'Heading',
      type: 'string',
      defaultValue: textWithImageData.text_with_image__heading,
    },
    subheading: {
      name: 'Subheading (optional)',
      type: 'string',
      defaultValue: textWithImageData.text_with_image__subheading,
    },
    text: {
      name: 'Text',
      type: 'string',
      defaultValue: textWithImageData.text_with_image__text,
    },
    linkContent: {
      name: 'Link Content (optional)',
      type: 'string',
      defaultValue: textWithImageData.text_with_image__link__content,
    },
  },
};

export const TextWithImage = ({
  width,
  position,
  focus,
  overline,
  heading,
  subheading,
  text,
  linkContent,
}) =>
  textWithImageTwig({
    ...imageData.responsive_images['3x2'],
    text_with_image__width: width,
    text_with_image__position: position,
    text_with_image__focus: focus,
    text_with_image__overline: overline,
    text_with_image__heading: heading,
    text_with_image__subheading: subheading,
    text_with_image__text: text,
    text_with_image__link__content: linkContent,
    text_with_image__link__url: textWithImageData.text_with_image__link__url,
  });
