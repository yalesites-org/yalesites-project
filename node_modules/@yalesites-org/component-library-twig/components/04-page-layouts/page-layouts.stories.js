import argTypes from './page-args';

import fullWidthTwig from './full-width.twig';

import utilityNavData from '../03-organisms/menu/utility-nav/utility-nav.yml';
import primaryNavData from '../03-organisms/menu/primary-nav/primary-nav.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Page Layouts/Page Layouts',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes,
};

export const fullWidth = ({
  siteName,
  headerBorderThickness,
  primaryNavPosition,
  siteHeaderTheme,
  utilityNavLinkContent,
  utilityNavSearch,
  siteFooterTheme,
  footerBorderThickness,
}) =>
  fullWidthTwig({
    site_name: siteName,
    site_header__border_thickness: headerBorderThickness,
    site_header__nav_position: primaryNavPosition,
    site_header__theme: siteHeaderTheme,
    site_footer__border_thickness: footerBorderThickness,
    site_footer__theme: siteFooterTheme,
    utility_nav__items: utilityNavData.items,
    utility_nav__link__content: utilityNavLinkContent,
    utility_nav__link__url: '#',
    utility_nav__search: utilityNavSearch,
    primary_nav__items: primaryNavData.items,
  });
