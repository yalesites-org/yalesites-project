// Shared Storybook args.
import argTypes from '../../04-page-layouts/cl-page-args';

// Twig files.
import profilePageTwig from './profile.twig';

// Data files.
import utilityNavData from '../../03-organisms/menu/utility-nav/utility-nav.yml';
import primaryNavData from '../../03-organisms/menu/primary-nav/primary-nav.yml';
import breadcrumbData from '../../03-organisms/menu/breadcrumbs/breadcrumbs.yml';
import imageData from '../../01-atoms/images/image/image.yml';
import textWithImageData from '../../02-molecules/text-with-image/text-with-image.yml';
import referenceCardData from '../../02-molecules/cards/reference-card/examples/post-card.yml';
import socialLinksData from '../../02-molecules/social-links/social-links.yml';
import videoData from '../../02-molecules/video/video.yml';
import accordionData from '../../02-molecules/accordion/accordion.yml';
import tabData from '../../02-molecules/tabs/tabs.yml';

// JavaScript.
import '../../00-tokens/layout/yds-layout';
import '../../01-atoms/controls/text-link/yds-text-link';

/**
 * Storybook Definition.
 */
export default {
  title: 'Page Examples/Profiles',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes,
};

export const ProfilePage = ({
  siteName,
  globalTheme = localStorage.getItem('yds-cl-twig-global-theme'),
  menuVariation = localStorage.getItem('yds-cl-twig-menu-variation'),
  headerBorderThickness = localStorage.getItem(
    'yds-cl-twig-header-border-thickness',
  ),
  primaryNavPosition = localStorage.getItem('yds-cl-twig-primary-nav-position'),
  siteHeaderTheme = localStorage.getItem('yds-cl-twig-site-header-theme'),
  siteHeaderAccent = localStorage.getItem('yds-cl-twig-site-header-accent'),
  utilityNavLinkContent,
  utilityNavSearch,
  allowAnimatedItems = localStorage.getItem('yds-cl-twig-animate-items'),
  siteFooterVariation = localStorage.getItem(
    'yds-cl-twig-site-footer-variation',
  ),
  siteFooterTheme = localStorage.getItem('yds-cl-twig-site-footer-theme'),
  siteFooterAccent = localStorage.getItem('yds-cl-twig-site-footer-accent'),
  footerBorderThickness = localStorage.getItem(
    'yds-cl-twig-footer-border-thickness',
  ),
}) =>
  profilePageTwig({
    site_name: siteName,
    site_animate_components: allowAnimatedItems,
    site_global__theme: globalTheme,
    site_header__border_thickness: headerBorderThickness,
    site_header__nav_position: primaryNavPosition,
    site_header__theme: siteHeaderTheme,
    site_header__accent: siteHeaderAccent,
    site_footer__variation: siteFooterVariation,
    site_footer__border_thickness: footerBorderThickness,
    site_footer__theme: siteFooterTheme,
    site_footer__accent: siteFooterAccent,
    utility_nav__items: utilityNavData.items,
    primary_nav__items: primaryNavData.items,
    menu__variation: menuVariation,
    utility_nav__link__content: utilityNavLinkContent,
    utility_nav__link__url: '#',
    utility_nav__search: utilityNavSearch,
    breadcrumbs__items: breadcrumbData.items,
    ...imageData.responsive_images['3x2'],
    ...textWithImageData,
    ...referenceCardData,
    ...socialLinksData,
    ...videoData,
    ...accordionData,
    ...tabData,
  });
