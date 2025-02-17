import tokens from '@yalesites-org/tokens/build/json/tokens.json';

import bannerTwig from './action/yds-action-banner.twig';
import grandHeroTwig from './grand-hero/yds-grand-hero.twig';
import imageBannerTwig from './image/yds-image-banner.twig';
import videoBannerTwig from './video/yds-video-banner.twig';

import bannerData from './banner.yml';
import grandHeroData from './grand-hero.yml';
import videoBannerData from '../../01-atoms/videos/video-embed/video-embed.yml';

import imageData from '../../01-atoms/images/image/image.yml';

const colorPairingsData = Object.keys(tokens['component-themes']);

const bannerArgTypes = {
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
  linkContentTwo: {
    name: 'Link Content Two',
    type: 'string',
    defaultValue: bannerData.banner__link__content_two,
  },
};

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Banners',
  parameters: {
    layout: 'fullscreen',
  },
  args: {
    heading: bannerData.banner__heading,
    snippet: bannerData.banner__snippet,
    linkContent: bannerData.banner__link__content,
    linkContentTwo: bannerData.banner__link__content_two,
  },
  argTypes: {
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
  linkContentTwo,
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
    banner__link__content_two: linkContentTwo,
    banner__link__url_two: bannerData.banner__link__url_two,
    banner__link__style: linkStyle,
    banner__content__layout: contentLayout,
    banner__content__background: bgColor,
  });
ActionBanner.argTypes = {
  ...bannerArgTypes,
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
  linkContentTwo,
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
    grand_hero__link__content_two: linkContentTwo,
    grand_hero__link__url_two: grandHeroData.grand_hero__link__url_two,
    grand_hero__content__background: bgColor,
    grand_hero__overlay_variation: overlayVariation,
    grand_hero__size: size,
    grand_hero__video: withVideo ? 'true' : 'false',
  });
GrandHeroBanner.argTypes = {
  ...bannerArgTypes,
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

export const ImageBanner = ({ bgColor, size, withVideo }) =>
  imageBannerTwig({
    ...imageData.responsive_images['16x9'],
    image_banner__content__background: bgColor,
    image_banner__overlay_variation: 'full',
    image_banner__size: size,
    image_banner__video: withVideo ? 'true' : 'false',
  });
ImageBanner.argTypes = {
  size: {
    name: 'Image Size',
    type: 'select',
    options: ['tall', 'short'],
    defaultValue: 'tall',
  },
  withVideo: {
    name: 'With Video',
    type: 'boolean',
    defaultValue: false,
  },
};

export const VideoBanner = ({ width }) =>
  videoBannerTwig({
    video_banner__content: videoBannerData.video_embed__content,
    video_banner__width: width,
  });
VideoBanner.argTypes = {
  width: {
    name: 'Video Width',
    type: 'select',
    options: ['max', 'full'],
  },
};
VideoBanner.args = {
  width: 'max',
};
