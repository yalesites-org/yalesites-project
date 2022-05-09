import tokens from '@yalesites-org/tokens/build/json/tokens.json';

import siteFooterTwig from './site-footer.twig';
import siteFooterExamples from './_site-footer--examples.twig';

const siteFooterThemes = { themes: tokens['site-footer-themes'] };
const borderThicknessOptions = Object.keys(tokens.border.thickness);
const siteFooterThemeOptions = Object.keys(tokens['site-footer-themes']);

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
    siteFooterTheme: {
      options: siteFooterThemeOptions,
      type: 'select',
      defaultValue: 'white',
    },
  },
};

export const Footer = ({ borderThickness, siteFooterTheme }) =>
  siteFooterTwig({
    site_footer__border_thickness: borderThickness,
    site_footer__theme: siteFooterTheme,
  });

export const FooterExamples = ({ borderThickness }) =>
  siteFooterExamples({
    ...siteFooterThemes,
    site_footer__border_thickness: borderThickness,
  });
