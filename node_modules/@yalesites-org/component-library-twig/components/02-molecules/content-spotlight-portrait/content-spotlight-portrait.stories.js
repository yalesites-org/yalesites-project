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
      defaultValue: 'default',
    },
    position: {
      name: 'Image Position',
      type: 'select',
      options: ['image-left', 'image-right'],
      defaultValue: 'image-left',
    },
    imageStyle: {
      name: 'Image Style',
      type: 'select',
      options: ['inline', 'offset'],
      defaultValue: 'inline',
    },
    overline: {
      name: 'Overline (optional)',
      type: 'string',
      defaultValue: null,
    },
    heading: {
      name: 'Heading',
      type: 'string',
      defaultValue:
        contentSpotlightPortraitData.content_spotlight_portrait__heading,
    },
    subheading: {
      name: 'Subheading (optional)',
      type: 'string',
      defaultValue:
        contentSpotlightPortraitData.content_spotlight_portrait__subheading,
    },
    text: {
      name: 'Text',
      type: 'string',
      defaultValue:
        contentSpotlightPortraitData.content_spotlight_portrait__text,
    },
    linkContent: {
      name: 'Link Content (optional)',
      type: 'string',
      defaultValue:
        contentSpotlightPortraitData.content_spotlight_portrait__link__content,
    },
  },
};

export const ContentSpotlightPortrait = ({
  position,
  overline,
  heading,
  subheading,
  text,
  linkContent,
  componentTheme,
  imageStyle,
}) =>
  contentSpotlightPortraitTwig({
    ...imageData.responsive_images['2x3'],
    content_spotlight_portrait__theme: componentTheme,
    content_spotlight_portrait__position: position,
    content_spotlight_portrait__style: imageStyle,
    content_spotlight_portrait__overline: overline,
    content_spotlight_portrait__heading: heading,
    content_spotlight_portrait__subheading: subheading,
    content_spotlight_portrait__text: text,
    content_spotlight_portrait__link__content: linkContent,
    content_spotlight_portrait__link__url:
      contentSpotlightPortraitData.content_spotlight_portrait__link__url,
  });
