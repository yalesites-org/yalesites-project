import tokens from '@yalesites-org/tokens/build/json/tokens.json';
// get global themes as `label` : `key` values to pass into options as array.
import getGlobalThemes from '../../00-tokens/colors/color-global-themes';

import siteHeaderTwig from './yds-site-header.twig';
import siteHeaderExamples from './_site-header--examples.twig';

import utilityNavData from '../menu/utility-nav/utility-nav.yml';
import primaryNavData from '../menu/primary-nav/primary-nav.yml';
import imageData from '../../01-atoms/images/image/image.yml';

import '../../02-molecules/menu/menu-toggle/yds-menu-toggle';

// JavaScript to handle size
import './yds-site-header';

const siteHeaderThemes = { themes: tokens['site-header-themes'] };
const siteGlobalThemes = { themes: tokens['global-themes'] };
const borderThicknessOptions = Object.keys(tokens.border.thickness);
const siteHeaderThemeOptions = Object.keys(tokens['site-header-themes']);
const siteGlobalThemeOptions = getGlobalThemes(tokens['global-themes']);
const siteHeaderAccents = [
  'one',
  'two',
  'three',
  'four',
  'five',
  'six',
  'seven',
  'eight',
];

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Site/Header',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    borderThickness: {
      name: 'Navigation Border Thickness',
      options: borderThicknessOptions,
      type: 'select',
    },
    primaryNavPosition: {
      name: 'Navigation Position',
      options: ['left', 'center', 'right'],
      type: 'select',
    },
    menuVariation: {
      name: 'Menu Variation',
      options: ['basic', 'mega', 'focus'],
      type: 'select',
    },
    siteHeaderImage: {
      name: 'Header With Image',
      type: 'boolean',
    },
    siteHeaderSiteNameImage: {
      name: 'Site Name is an Image',
      type: 'boolean',
    },
    siteWideHeaderName: {
      name: 'Site Wide Header Name',
      type: 'string',
    },
    siteWideHeaderUrl: {
      name: 'Site Wide Header URL',
      type: 'string',
    },
  },
  args: {
    borderThickness: '8',
    primaryNavPosition: 'left',
    menuVariation: 'basic',
    siteHeaderImage: false,
    siteHeaderSiteNameImage: false,
    siteHeaderTheme: 'one',
    siteHeaderAccent: 'one',
    siteWideHeaderName: 'Yale University',
    siteWideHeaderUrl: 'https://www.yale.edu',
  },
};

export const Header = ({
  borderThickness,
  primaryNavPosition,
  siteHeaderTheme,
  menuVariation,
  siteHeaderImage,
  siteHeaderSiteNameImage,
  siteHeaderAccent,
  siteWideHeaderName,
  siteWideHeaderUrl,
}) =>
  siteHeaderTwig({
    ...imageData.responsive_images['16x9'],
    site_name: 'Department of Chemistry',
    site_header__border_thickness: borderThickness,
    site_header__nav_position: primaryNavPosition,
    site_header__theme: siteHeaderTheme,
    site_header__accent: siteHeaderAccent,
    site_header__menu__variation: menuVariation,
    site_header__background_image: siteHeaderImage,
    site_header__site_name_is_image: siteHeaderSiteNameImage,
    site_header__branding_name: siteWideHeaderName,
    site_header__branding_link: siteWideHeaderUrl,
    utility_nav__items: utilityNavData.items,
    primary_nav__items: primaryNavData.items,
  });

Header.argTypes = {
  siteHeaderTheme: {
    name: 'Header Theme (dial)',
    options: siteHeaderThemeOptions,
    type: 'select',
  },
  siteHeaderAccent: {
    name: 'Header Accent Color (dial)',
    options: siteHeaderAccents,
    type: 'select',
  },
  siteHeaderImage: {
    name: 'With image',
    type: 'boolean',
  },
};

export const HeaderExamples = ({
  borderThickness,
  primaryNavPosition,
  menuVariation,
  globalTheme,
  siteHeaderAccent,
  siteHeaderImage,
  siteHeaderSiteNameImage,
  siteWideHeaderName,
  siteWideHeaderUrl,
}) =>
  siteHeaderExamples({
    ...siteGlobalThemes,
    ...siteHeaderThemes,
    ...siteHeaderAccents,
    ...imageData.responsive_images['16x9'],
    site_name: 'Department of Chemistry',
    site_global__theme: globalTheme,
    site_header__accent: siteHeaderAccent,
    site_header__border_thickness: borderThickness,
    site_header__nav_position: primaryNavPosition,
    site_header__menu__variation: menuVariation,
    site_header__background_image: siteHeaderImage,
    site_header__site_name_is_image: siteHeaderSiteNameImage,
    site_header__branding_name: siteWideHeaderName,
    site_header__branding_link: siteWideHeaderUrl,
    utility_nav__items: utilityNavData.items,
    primary_nav__items: primaryNavData.items,
  });

HeaderExamples.argTypes = {
  globalTheme: {
    name: 'Global Theme (lever)',
    options: siteGlobalThemeOptions,
    type: 'select',
  },
  siteHeaderAccent: {
    name: 'Header Accent Color (dial)',
    options: siteHeaderAccents,
    type: 'select',
  },
  siteHeaderImage: {
    name: 'Header With Image',
    type: 'boolean',
  },
};
