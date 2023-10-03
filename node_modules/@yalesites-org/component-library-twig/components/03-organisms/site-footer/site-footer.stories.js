import tokens from '@yalesites-org/tokens/build/json/tokens.json';
import getGlobalThemes from '../../00-tokens/colors/color-global-themes';

import siteFooterTwig from './yds-site-footer.twig';
import siteFooterExamples from './_site-footer--examples.twig';

import socialLinksData from '../../02-molecules/social-links/social-links.yml';

const siteFooterThemes = { themes: tokens['site-footer-themes'] };
const siteGlobalThemes = { themes: tokens['global-themes'] };
const borderThicknessOptions = Object.keys(tokens.border.thickness);
const siteFooterThemeOptions = Object.keys(tokens['site-footer-themes']);
const siteGlobalThemeOptions = getGlobalThemes(tokens['global-themes']);
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

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Site/Footer',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    borderThickness: {
      options: borderThicknessOptions,
      type: 'select',
      defaultValue: '8',
    },
  },
};

export const Footer = ({
  borderThickness,
  siteFooterTheme,
  siteFooterVariation,
  siteFooterAccent,
}) =>
  siteFooterTwig({
    ...socialLinksData,
    ...siteFooterAccents,
    site_footer__border_thickness: borderThickness,
    site_footer__theme: siteFooterTheme,
    site_footer__accent: siteFooterAccent,
    site_footer__variation: siteFooterVariation,
  });

Footer.argTypes = {
  siteFooterTheme: {
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

export const FooterExamples = ({
  borderThickness,
  globalTheme,
  siteFooterVariation,
  siteFooterAccent,
}) =>
  siteFooterExamples({
    ...socialLinksData,
    ...siteFooterThemes,
    ...siteGlobalThemes,
    ...siteFooterAccents,
    site_global__theme: globalTheme,
    site_footer__accent: siteFooterAccent,
    site_footer__border_thickness: borderThickness,
    site_footer__variation: siteFooterVariation,
  });

FooterExamples.argTypes = {
  globalTheme: {
    name: 'Global Theme (lever)',
    options: siteGlobalThemeOptions,
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
