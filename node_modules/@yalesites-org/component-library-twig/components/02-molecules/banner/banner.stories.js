import tokens from '@yalesites-org/tokens/build/json/tokens.json';

import bannerTwig from './action/yds-action-banner.twig';
import grandHeroTwig from './grand-hero/yds-grand-hero.twig';

import bannerData from './banner.yml';
import grandHeroData from './grand-hero.yml';

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
    bgColor: {
      name: 'Component Theme (dial)',
      type: 'select',
      options: colorPairingsData,
      defaultValue: 'one',
    },
  },
};

export const ActionBanner = ({
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
ActionBanner.argTypes = {
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
};

export const GrandHeroBanner = ({
  heading,
  snippet,
  linkContent,
  bgColor,
  overlayVariation,
  size,
  withVideo,
}) =>
  grandHeroTwig({
    ...imageData.responsive_images['16x9'],
    grand_hero__heading: heading,
    grand_hero__snippet: snippet,
    grand_hero__link__content: linkContent,
    grand_hero__link__url: grandHeroData.grand_hero__link__url,
    grand_hero__content__background: bgColor,
    grand_hero__overlay_variation: overlayVariation,
    grand_hero__size: size,
    grand_hero__video: withVideo ? 'true' : 'false',
  });
GrandHeroBanner.argTypes = {
  overlayVariation: {
    name: 'Content Overlay',
    type: 'select',
    options: ['contained', 'full'],
    defaultValue: 'full',
  },
  size: {
    name: 'Content Size',
    type: 'select',
    options: ['reduced', 'full'],
    defaultValue: 'full',
  },
  withVideo: {
    name: 'With Video',
    type: 'boolean',
    defaultValue: false,
  },
};
