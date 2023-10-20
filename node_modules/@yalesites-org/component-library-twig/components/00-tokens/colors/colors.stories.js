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
const siteFooterAccents = [
  'one',
  'two',
  'three',
  'four',
  'five',
  'six',
  'seven',
  'eight',
];

const ctaButtonThemeOptions = Object.keys(tokens['button-cta-themes']);

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
  ctaButtonTheme,
  qlTheme,
  quoteTheme,
  tabTheme,
  bannerTheme,
  siteHeaderTwig,
  siteHeaderTheme,
  siteFooterTheme,
  siteHeaderAccent,
  siteFooterAccent,
  siteFooterVariation,
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
    ...siteHeaderAccents,
    ...siteFooterAccents,
    ...ctaButtonThemeOptions,
    site_name: 'Department of Chemistry',
    site_header__border_thickness: '8',
    site_header__nav_position: 'left',
    site_header__theme: siteHeaderTheme,
    site_header__accent: siteHeaderAccent,
    site_header__menu__variation: 'basic',
    utility_nav__items: utilityNavData.items,
    primary_nav__items: primaryNavData.items,
    quick_links__heading: heading,
    quick_links__description: description,
    quick_links__image: image,
    quick_links__background_color: qlTheme,
    callout__background_color: calloutTheme,
    cta_button__component_theme: ctaButtonTheme,
    quick_links__links: quickLinksData.quick_links__links,
    tabs__theme: tabTheme,
    banner__content__background: bannerTheme,
    pull_quote__accent_theme: quoteTheme,
    site_footer__theme: siteFooterTheme,
    site_footer__accent: siteFooterAccent,
    site_footer__variation: siteFooterVariation,
  });
ComponentThemeColorPairings.argTypes = {
  siteHeaderTheme: {
    name: 'Header Theme (dial)',
    options: siteHeaderThemeOptions,
    type: 'select',
    defaultValue: 'one',
  },
  siteHeaderAccent: {
    name: 'Header Accent Color (dial)',
    options: siteHeaderAccents,
    type: 'select',
    defaultValue: 'one',
  },
  bannerTheme: {
    name: 'Banner Theme (dial)',
    type: 'select',
    options: ['one', 'two', 'three'],
    defaultValue: 'one',
  },
  ctaButtonTheme: {
    name: 'Button CTA Theme (dial)',
    type: 'select',
    options: ctaButtonThemeOptions,
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
  siteFooterAccent: {
    name: 'Footer Accent Color (dial)',
    options: siteFooterAccents,
    type: 'select',
    defaultValue: 'one',
  },
  siteFooterVariation: {
    name: 'Footer Variation (dial)',
    options: ['basic', 'mega'],
    type: 'select',
    defaultValue: 'basic',
  },
};

export const GlobalThemeColorPairings = ({
  heading,
  description,
  image,
  globalTheme,
  calloutTheme,
  ctaButtonTheme,
  qlTheme,
  quoteTheme,
  tabTheme,
  bannerTheme,
  siteHeaderTwig,
  siteHeaderTheme,
  siteHeaderAccent,
  siteFooterTheme,
  siteFooterAccent,
  siteFooterVariation,
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
    ...siteHeaderAccents,
    ...siteFooterAccents,
    site_global__theme: globalTheme,
    site_name: 'Department of Chemistry',
    site_header__border_thickness: '8',
    site_header__nav_position: 'left',
    site_header__theme: siteHeaderTheme,
    site_header__accent: siteHeaderAccent,
    site_header__menu__variation: 'basic',
    utility_nav__items: utilityNavData.items,
    primary_nav__items: primaryNavData.items,
    quick_links__heading: heading,
    quick_links__description: description,
    quick_links__image: image,
    quick_links__background_color: qlTheme,
    callout__background_color: calloutTheme,
    cta_button__component_theme: ctaButtonTheme,
    quick_links__links: quickLinksData.quick_links__links,
    tabs__theme: tabTheme,
    banner__content__background: bannerTheme,
    pull_quote__accent_theme: quoteTheme,
    site_footer__theme: siteFooterTheme,
    site_footer__accent: siteFooterAccent,
    site_footer__variation: siteFooterVariation,
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
  siteHeaderAccent: {
    name: 'Header Accent Color (dial)',
    options: siteHeaderAccents,
    type: 'select',
    defaultValue: 'one',
  },
  bannerTheme: {
    name: 'Banner Theme (dial)',
    type: 'select',
    options: ['one', 'two', 'three'],
    defaultValue: 'one',
  },
  ctaButtonTheme: {
    name: 'Button CTA Theme (dial)',
    type: 'select',
    options: ctaButtonThemeOptions,
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
  siteFooterAccent: {
    name: 'Footer Accent Color (dial)',
    options: siteFooterAccents,
    type: 'select',
    defaultValue: 'one',
  },
  siteFooterVariation: {
    name: 'Footer Variation (dial)',
    options: ['basic', 'mega'],
    type: 'select',
    defaultValue: 'basic',
  },
};
