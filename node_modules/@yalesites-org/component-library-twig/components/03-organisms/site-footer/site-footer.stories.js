import tokens from '@yalesites-org/tokens/build/json/tokens.json';

import siteFooterTwig from './yds-site-footer.twig';
import siteFooterExamples from './_site-footer--examples.twig';

import socialLinksData from '../../02-molecules/social-links/social-links.yml';
import linkGroupData from '../../02-molecules/link-group/link-group.yml';

const siteFooterThemes = { themes: tokens['site-footer-themes'] };
const siteGlobalThemes = { themes: tokens['global-themes'] };
const borderThicknessOptions = Object.keys(tokens.border.thickness);
const siteFooterThemeOptions = Object.keys(tokens['site-footer-themes']);
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
    },
  },
  args: {
    borderThickness: '8',
    siteFooterAccent: 'one',
    siteFooterVariation: 'basic',
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
    ...linkGroupData,
    site_footer__border_thickness: borderThickness,
    site_footer__theme: siteFooterTheme,
    site_footer__accent: siteFooterAccent,
    site_footer__variation: siteFooterVariation,
    site_footer__content_text:
      'This is <a href="https://example.com">example text</a> for footer content <a href="https://example.com/blah">with a link</a>.',
  });

Footer.args = {
  siteFooterTheme: 'one',
};

Footer.argTypes = {
  siteFooterTheme: {
    name: 'Footer Theme (dial)',
    options: siteFooterThemeOptions,
    type: 'select',
  },
  siteFooterAccent: {
    name: 'Footer Accent Color (dial)',
    options: siteFooterAccents,
    type: 'select',
  },
  siteFooterVariation: {
    name: 'Footer Variation (dial)',
    options: ['basic', 'mega'],
    type: 'select',
  },
};

export const FooterExamples = ({
  borderThickness,
  siteFooterVariation,
  siteFooterAccent,
}) =>
  siteFooterExamples({
    ...socialLinksData,
    ...siteFooterThemes,
    ...siteGlobalThemes,
    ...siteFooterAccents,
    site_footer__accent: siteFooterAccent,
    site_footer__border_thickness: borderThickness,
    site_footer__variation: siteFooterVariation,
  });

FooterExamples.argTypes = {
  siteFooterAccent: {
    name: 'Footer Accent Color (dial)',
    options: siteFooterAccents,
    type: 'select',
  },
  siteFooterVariation: {
    name: 'Footer Variation (dial)',
    options: ['basic', 'mega'],
    type: 'select',
  },
};
