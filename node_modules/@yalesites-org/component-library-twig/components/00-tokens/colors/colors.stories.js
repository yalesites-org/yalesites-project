import tokens from '@yalesites-org/tokens/build/json/tokens.json';
import getGlobalThemes from './color-global-themes';

import colorsTwig from './colors.twig';
import colorComponentThemeTwig from './color-component-theme-pairings.twig';
import colorGlobalThemeTwig from './color-global-themes.twig';
import colorGlobalThemePairingTwig from './color-global-theme-pairings.twig';
import colorBasicThemesTwig from './color-basic-themes.twig';

import utilityNavData from '../../03-organisms/menu/utility-nav/utility-nav.yml';
import primaryNavData from '../../03-organisms/menu/primary-nav/primary-nav.yml';

// JavaScript to handle size
import '../../03-organisms/site-header/yds-site-header';
import '../../02-molecules/menu/menu-toggle/yds-menu-toggle';
import '../../02-molecules/tabs/yds-tabs';

import quickLinksData from '../../02-molecules/quick-links/quick-links.yml';
import imageData from '../../01-atoms/images/image/image.yml';
import tabData from '../../02-molecules/tabs/tabs.yml';
import bannerData from '../../02-molecules/banner/banner.yml';

const colorsData = {
  colors: {
    blue: tokens.color.blue,
    green: tokens.color.green,
    purple: tokens.color.purple,
    orange: tokens.color.orange,
    yellow: tokens.color.yellow,
    basic: tokens.color.basic,
    gray: tokens.color.gray,
  },
};

const colorComponentThemeData = { themes: tokens['component-themes'] };
const colorBasicThemeData = { themes: tokens['basic-themes'] };
const colorGlobalThemeData = { globalThemes: tokens['global-themes'] };
const siteHeaderThemes = { themes: tokens['site-header-themes'] };
const siteHeaderThemeOptions = Object.keys(tokens['site-header-themes']);
const siteFooterThemes = { themes: tokens['site-footer-themes'] };
const siteFooterThemeOptions = Object.keys(tokens['site-footer-themes']);

// get global themes as `label` : `key` values to pass into options as array.
const siteGlobalThemeOptions = getGlobalThemes(tokens['global-themes']);

export default {
  title: 'Tokens/Colors',
};

export const Colors = () => colorsTwig(colorsData);
export const ColorGlobalThemes = () =>
  colorGlobalThemeTwig(colorGlobalThemeData);

export const ColorBasicThemes = () => `
  <h2>These pairings are selected to support accessibility standards.</h2>
  <p>This page is useful to check the accessibility of various components against the available background colors.</p>

  ${colorBasicThemesTwig(colorBasicThemeData)}
`;

export const ComponentThemeColorPairings = ({
  heading,
  description,
  image,
  calloutTheme,
  qlTheme,
  quoteTheme,
  tabTheme,
  bannerTheme,
  siteHeaderTwig,
  siteHeaderTheme,
  siteFooterTheme,
}) =>
  colorComponentThemeTwig({
    ...imageData.responsive_images['16x9'],
    ...tabData,
    ...bannerData,
    ...siteHeaderTwig,
    ...siteHeaderThemes,
    ...siteFooterThemes,
    ...colorComponentThemeData,
    ...utilityNavData,
    ...primaryNavData,
    site_name: 'Department of Chemistry',
    site_header__border_thickness: '8',
    site_header__nav_position: 'left',
    site_header__theme: siteHeaderTheme,
    site_header__menu__variation: 'basic',
    utility_nav__items: utilityNavData.items,
    primary_nav__items: primaryNavData.items,
    quick_links__heading: heading,
    quick_links__description: description,
    quick_links__image: image,
    quick_links__background_color: qlTheme,
    callout__background_color: calloutTheme,
    quick_links__links: quickLinksData.quick_links__links,
    tabs__theme: tabTheme,
    banner__content__background: bannerTheme,
    pull_quote__accent_theme: quoteTheme,
    site_footer__theme: siteFooterTheme,
  });
ComponentThemeColorPairings.argTypes = {
  siteHeaderTheme: {
    name: 'Header Theme (dial)',
    options: siteHeaderThemeOptions,
    type: 'select',
    defaultValue: 'one',
  },
  bannerTheme: {
    name: 'Banner Theme (dial)',
    type: 'select',
    options: ['one', 'two', 'three'],
    defaultValue: 'one',
  },
  qlTheme: {
    name: 'Quick Links Theme (dial)',
    type: 'select',
    options: ['one', 'two', 'three'],
    defaultValue: 'one',
  },
  quoteTheme: {
    name: 'Quote Theme (dial)',
    type: 'select',
    options: ['one', 'two', 'three'],
    defaultValue: 'one',
  },
  calloutTheme: {
    name: 'Callout Theme (dial)',
    type: 'select',
    options: ['one', 'two', 'three'],
    defaultValue: 'one',
  },
  tabTheme: {
    name: 'Tabs Theme (dial)',
    type: 'select',
    options: ['one', 'two', 'three'],
    defaultValue: 'one',
  },
  siteFooterTheme: {
    name: 'Footer Theme (dial)',
    options: siteFooterThemeOptions,
    type: 'select',
    defaultValue: 'one',
  },
};

export const GlobalThemeColorPairings = ({
  heading,
  description,
  image,
  globalTheme,
  calloutTheme,
  qlTheme,
  quoteTheme,
  tabTheme,
  bannerTheme,
  siteHeaderTwig,
  siteHeaderTheme,
  siteFooterTheme,
}) =>
  colorGlobalThemePairingTwig({
    ...imageData.responsive_images['16x9'],
    ...colorGlobalThemeData,
    ...colorGlobalThemeTwig,
    ...tabData,
    ...bannerData,
    ...siteHeaderTwig,
    ...siteHeaderThemes,
    ...siteFooterThemes,
    ...utilityNavData,
    ...primaryNavData,
    site_global__theme: globalTheme,
    site_name: 'Department of Chemistry',
    site_header__border_thickness: '8',
    site_header__nav_position: 'left',
    site_header__theme: siteHeaderTheme,
    site_header__menu__variation: 'basic',
    utility_nav__items: utilityNavData.items,
    primary_nav__items: primaryNavData.items,
    quick_links__heading: heading,
    quick_links__description: description,
    quick_links__image: image,
    quick_links__background_color: qlTheme,
    callout__background_color: calloutTheme,
    quick_links__links: quickLinksData.quick_links__links,
    tabs__theme: tabTheme,
    banner__content__background: bannerTheme,
    pull_quote__accent_theme: quoteTheme,
    site_footer__theme: siteFooterTheme,
  });

GlobalThemeColorPairings.argTypes = {
  globalTheme: {
    name: 'Global Theme (lever)',
    options: siteGlobalThemeOptions,
    type: 'select',
    defaultValue: 'one',
  },
  siteHeaderTheme: {
    name: 'Header Theme (dial)',
    options: siteHeaderThemeOptions,
    type: 'select',
    defaultValue: 'one',
  },
  bannerTheme: {
    name: 'Banner Theme (dial)',
    type: 'select',
    options: ['one', 'two', 'three'],
    defaultValue: 'one',
  },
  qlTheme: {
    name: 'Quick Links Theme (dial)',
    type: 'select',
    options: ['one', 'two', 'three'],
    defaultValue: 'one',
  },
  quoteTheme: {
    name: 'Quote Theme (dial)',
    type: 'select',
    options: ['one', 'two', 'three'],
    defaultValue: 'one',
  },
  calloutTheme: {
    name: 'Callout Theme (dial)',
    type: 'select',
    options: ['one', 'two', 'three'],
    defaultValue: 'one',
  },
  tabTheme: {
    name: 'Tabs Theme (dial)',
    type: 'select',
    options: ['one', 'two', 'three'],
    defaultValue: 'one',
  },
  siteFooterTheme: {
    name: 'Footer Theme (dial)',
    options: siteFooterThemeOptions,
    type: 'select',
    defaultValue: 'one',
  },
};
