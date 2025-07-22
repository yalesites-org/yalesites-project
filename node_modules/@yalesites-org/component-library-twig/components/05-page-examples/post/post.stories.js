// Shared Storybook args.
import argTypes from '../../04-page-layouts/cl-page-args';

// Twig files.
import postArticleTwig from './post-article.twig';
import postGridTwig from './post-grid.twig';

// Data files.
import utilityNavData from '../../03-organisms/menu/utility-nav/utility-nav.yml';
import primaryNavData from '../../03-organisms/menu/primary-nav/primary-nav.yml';
import breadcrumbData from '../../03-organisms/menu/breadcrumbs/breadcrumbs.yml';
import imageData from '../../01-atoms/images/image/image.yml';
import socialLinksData from '../../02-molecules/social-links/social-links.yml';
import referenceCardData from '../../02-molecules/cards/reference-card/examples/post-card.yml';

// JavaScript.
import '../../00-tokens/layout/yds-layout';
import '../../02-molecules/read-time/yds-read-time';

// Utility for converting argTypes to args
import argTypesToArgs from '../../utility';

/**
 * Storybook Definition.
 */
export default {
  title: 'Page Examples/Post',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes,
  args: argTypesToArgs(argTypes),
};

export const PostArticle = ({
  siteName,
  pageTitle,
  meta,
  allowAnimatedItems = localStorage.getItem('yds-cl-twig-animate-items'),
  menuVariation = localStorage.getItem('yds-cl-twig-menu-variation'),
  headerBorderThickness = localStorage.getItem(
    'yds-cl-twig-header-border-thickness',
  ),
  primaryNavPosition = localStorage.getItem('yds-cl-twig-primary-nav-position'),
  siteHeaderTheme = localStorage.getItem('yds-cl-twig-site-header-theme'),
  siteHeaderAccent = localStorage.getItem('yds-cl-twig-site-header-accent'),
  utilityNavLinkContent,
  utilityNavSearch,
  siteFooterVariation = localStorage.getItem(
    'yds-cl-twig-site-footer-variation',
  ),
  siteFooterTheme = localStorage.getItem('yds-cl-twig-site-footer-theme'),
  siteFooterAccent = localStorage.getItem('yds-cl-twig-site-footer-accent'),
  footerBorderThickness = localStorage.getItem(
    'yds-cl-twig-footer-border-thickness',
  ),
  showSocialMediaSharingLinks = false,
}) =>
  postArticleTwig({
    site_name: siteName,
    page_title__heading: pageTitle,
    page_title__meta: meta,
    page_title__show_social_media_sharing_links: showSocialMediaSharingLinks
      ? 'true'
      : 'false',
    site_animate_components: allowAnimatedItems,
    site_header__border_thickness: headerBorderThickness,
    site_header__branding_link: 'https://www.yale.edu',
    site_header__nav_position: primaryNavPosition,
    site_header__theme: siteHeaderTheme,
    site_header__accent: siteHeaderAccent,
    site_footer__variation: siteFooterVariation,
    site_footer__border_thickness: footerBorderThickness,
    site_footer__theme: siteFooterTheme,
    site_footer__accent: siteFooterAccent,
    utility_nav__items: utilityNavData.items,
    primary_nav__items: primaryNavData.items,
    site_header__menu__variation: menuVariation,
    utility_nav__link__content: utilityNavLinkContent,
    utility_nav__link__url: '#',
    utility_nav__search: utilityNavSearch,
    breadcrumbs__items: breadcrumbData.items,
    ...imageData.responsive_images['16x9'],
    image__srcset__1: imageData.responsive_images['4x3'].image__srcset,
    image__sizes__1: imageData.responsive_images['4x3'].image__sizes,
    image__alt__1: imageData.responsive_images['4x3'].image__alt,
    image__src__1: imageData.responsive_images['4x3'].image__src,
    image__srcset__wrapped: imageData.responsive_images['3x2'].image__srcset,
    image__sizes__wrapped: imageData.responsive_images['3x2'].image__sizes,
    image__alt__wrapped: imageData.responsive_images['3x2'].image__alt,
    image__src__wrapped: imageData.responsive_images['3x2'].image__src,
    ...socialLinksData,
    ...referenceCardData,
  });
PostArticle.argTypes = {
  showSocialMediaSharingLinks: {
    name: 'Show Social Media Sharing Links',
    type: 'boolean',
    defaultValue: false,
  },
};
PostArticle.args = {
  showSocialMediaSharingLinks: false,
};

export const postGridCustom = ({
  allowAnimatedItems = localStorage.getItem('yds-cl-twig-animate-items'),
}) =>
  postGridTwig({
    site_animate_components: allowAnimatedItems,
  });
