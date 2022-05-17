import tokens from '@yalesites-org/tokens/build/json/tokens.json';

import bannerTwig from './cta/cta-banner.twig';

import bannerData from './banner.yml';

import imageData from '../../01-atoms/images/image/image.yml';

const colorPairingsData = Object.keys(tokens['component-themes']);

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Banners',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    heading: {
      name: 'Heading',
      type: 'string',
      defaultValue: bannerData.banner__heading,
    },
    snippet: {
      name: 'Snippet',
      type: 'string',
      defaultValue: bannerData.banner__snippet,
    },
    linkContent: {
      name: 'Link Content',
      type: 'string',
      defaultValue: bannerData.banner__link__content,
    },
    linkStyle: {
      name: 'Link Style',
      type: 'select',
      options: ['cta', 'text-link'],
      defaultValue: 'cta',
    },
    contentLayout: {
      name: 'Content Layout',
      type: 'select',
      options: ['bottom', 'left', 'right'],
      defaultValue: 'bottom',
    },
    bgColor: {
      name: 'Content Background Color',
      type: 'select',
      options: colorPairingsData,
      defaultValue: 'gray-800',
    },
  },
};

export const banner = ({
  heading,
  snippet,
  linkContent,
  linkStyle,
  contentLayout,
  bgColor,
}) =>
  bannerTwig({
    ...imageData.responsive_images['16x9'],
    banner__heading: heading,
    banner__snippet: snippet,
    banner__link__content: linkContent,
    banner__link__url: bannerData.banner__link__url,
    banner__link__style: linkStyle,
    banner__content__layout: contentLayout,
    banner__content__background: bgColor,
  });
