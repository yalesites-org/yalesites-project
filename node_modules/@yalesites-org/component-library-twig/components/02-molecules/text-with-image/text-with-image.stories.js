import tokens from '@yalesites-org/tokens/build/json/tokens.json';

// Twig templates
import textWithImageTwig from './yds-text-with-image.twig';

// Data files
import imageData from '../../01-atoms/images/image/image.yml';
import textWithImageData from './text-with-image.yml';

import '../content-spotlight-portrait/content-spotlights';

const colorPairingsData = Object.keys(tokens['component-themes']);
/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Content Spotlight',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    componentTheme: {
      name: 'Component Theme (dial)',
      type: 'select',
      options: colorPairingsData,
    },
    width: {
      name: 'Width',
      type: 'select',
      options: ['highlight', 'site'],
    },
    position: {
      name: 'Image Position',
      type: 'select',
      options: ['image-left', 'image-right'],
    },
    contentVerticalAlignment: {
      name: 'Content Vertical Alignment',
      type: 'select',
      options: ['top', 'middle', 'bottom'],
    },
    focus: {
      name: 'Focus',
      type: 'select',
      options: ['image', 'equal'],
    },
    overline: {
      name: 'Overline (optional)',
      type: 'string',
    },
    heading: {
      name: 'Heading',
      type: 'string',
    },
    subheading: {
      name: 'Subheading (optional)',
      type: 'string',
    },
    text: {
      name: 'Text',
      type: 'string',
    },
    linkContent: {
      name: 'Link Content (optional)',
      type: 'string',
    },
    linkTwoContent: {
      name: 'Second Link Content (optional)',
      type: 'string',
      defaultValue: textWithImageData.text_with_image__link_two__content,
    },
  },
  args: {
    componentTheme: 'default',
    width: 'site',
    position: 'image-left',
    contentVerticalAlignment: 'top',
    focus: 'equal',
    overline: null,
    heading: textWithImageData.text_with_image__heading,
    subheading: textWithImageData.text_with_image__subheading,
    text: textWithImageData.text_with_image__text,
    linkContent: textWithImageData.text_with_image__link__content,
  },
};

export const ContentSpotlightLandscape = ({
  width,
  position,
  contentVerticalAlignment,
  focus,
  overline,
  heading,
  subheading,
  text,
  linkContent,
  linkTwoContent,
  componentTheme,
}) =>
  textWithImageTwig({
    ...imageData.responsive_images['3x2'],
    text_with_image__theme: componentTheme,
    text_with_image__width: width,
    text_with_image__position: position,
    text_with_image__vertical_align: contentVerticalAlignment,
    text_with_image__focus: focus,
    text_with_image__overline: overline,
    text_with_image__heading: heading,
    text_with_image__subheading: subheading,
    text_with_image__text: text,
    text_with_image__link__content: linkContent,
    text_with_image__link__url: textWithImageData.text_with_image__link__url,
    text_with_image__link_two__content: linkTwoContent,
    text_with_image__link_two__url:
      textWithImageData.text_with_image__link_two__url,
  });
