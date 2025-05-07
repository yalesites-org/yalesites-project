import tokens from '@yalesites-org/tokens/build/json/tokens.json';

// Twig templates
import contentSpotlightPortraitTwig from './yds-content-spotlight-portrait.twig';

// Data files
import imageData from '../../01-atoms/images/image/image.yml';
import contentSpotlightPortraitData from './content-spotlight-portrait.yml';

import './content-spotlights';

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
    imageStyle: {
      name: 'Image Style',
      type: 'select',
      options: ['inline', 'offset'],
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
      defaultValue:
        contentSpotlightPortraitData.content_spotlight_portrait__link_two__content,
    },
  },
  args: {
    componentTheme: 'default',
    position: 'image-left',
    contentVerticalAlignment: 'middle',
    imageStyle: 'inline',
    overline: null,
    heading: contentSpotlightPortraitData.content_spotlight_portrait__heading,
    subheading:
      contentSpotlightPortraitData.content_spotlight_portrait__subheading,
    text: contentSpotlightPortraitData.content_spotlight_portrait__text,
    linkContent:
      contentSpotlightPortraitData.content_spotlight_portrait__link__content,
  },
};

export const ContentSpotlightPortrait = ({
  position,
  contentVerticalAlignment,
  overline,
  heading,
  subheading,
  text,
  linkContent,
  linkTwoContent,
  componentTheme,
  imageStyle,
}) =>
  contentSpotlightPortraitTwig({
    ...imageData.responsive_images['2x3'],
    content_spotlight_portrait__theme: componentTheme,
    content_spotlight_portrait__position: position,
    content_spotlight_portrait__vertical_align: contentVerticalAlignment,
    content_spotlight_portrait__style: imageStyle,
    content_spotlight_portrait__overline: overline,
    content_spotlight_portrait__heading: heading,
    content_spotlight_portrait__subheading: subheading,
    content_spotlight_portrait__text: text,
    content_spotlight_portrait__link__content: linkContent,
    content_spotlight_portrait__link__url:
      contentSpotlightPortraitData.content_spotlight_portrait__link__url,
    content_spotlight_portrait__link_two__content: linkTwoContent,
    content_spotlight_portrait__link_two__url:
      contentSpotlightPortraitData.content_spotlight_portrait__link_two__url,
  });
