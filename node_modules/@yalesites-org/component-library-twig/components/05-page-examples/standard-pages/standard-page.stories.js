import tokens from '@yalesites-org/tokens/build/json/tokens.json';

// Shared Storybook args.
import argTypes from '../../04-page-layouts/page-args';

// Twig files.
import standardPageTwig from './standard-page.twig';
import standardPageBannerTwig from './standard-page-with-banner.twig';
import standardPageSidebarTwig from './standard-page-with-sidebar.twig';

// Data files.
import utilityNavData from '../../03-organisms/menu/utility-nav/utility-nav.yml';
import primaryNavData from '../../03-organisms/menu/primary-nav/primary-nav.yml';
import breadcrumbData from '../../03-organisms/menu/breadcrumbs/breadcrumbs.yml';
import imageData from '../../01-atoms/images/image/image.yml';
import textWithImageData from '../../02-molecules/text-with-image/text-with-image.yml';
import bannerData from '../../02-molecules/banner/banner.yml';
import newsCardData from '../../02-molecules/cards/news-card/news-card.yml';

// JavaScript.
import '../../00-tokens/layout/layout';

const colorPairingsData = Object.keys(tokens['component-themes']);

/**
 * Storybook Definition.
 */
export default {
  title: 'Page Examples/Standard Pages',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    ...argTypes,
    introContent: {
      name: 'Intro Content',
      options: [
        'none',
        'image',
        'image--highlight',
        'image--feature',
        'image--max',
        'pop-out-image',
        'text-with-image',
        'text-with-image--highlight',
        'collection-featured',
        'collection-secondary',
      ],
      type: 'select',
      defaultValue: 'none',
    },
    calloutBackground: {
      name: 'Callout Background Color',
      type: 'select',
      options: ['blue-yale', 'gray-700', 'beige'],
      defaultValue: 'beige',
    },
  },
};

export const Basic = ({
  siteName,
  pageTitle,
  headerBorderThickness,
  primaryNavPosition,
  siteHeaderTheme,
  utilityNavLinkContent,
  utilityNavSearch,
  siteFooterTheme,
  footerBorderThickness,
  introContent,
  calloutBackground,
}) =>
  standardPageTwig({
    site_name: siteName,
    page_title__heading: pageTitle,
    page_title__meta: null,
    site_header__border_thickness: headerBorderThickness,
    site_header__nav_position: primaryNavPosition,
    site_header__theme: siteHeaderTheme,
    site_footer__border_thickness: footerBorderThickness,
    site_footer__theme: siteFooterTheme,
    utility_nav__items: utilityNavData.items,
    primary_nav__items: primaryNavData.items,
    utility_nav__link__content: utilityNavLinkContent,
    utility_nav__link__url: '#',
    utility_nav__search: utilityNavSearch,
    breadcrumbs__items: breadcrumbData.items,
    ...imageData.responsive_images['16x9'],
    intro_content: introContent,
    callout__background_color: calloutBackground,
    ...textWithImageData,
    ...newsCardData,
  });

export const WithBanner = ({
  siteName,
  pageTitle,
  headerBorderThickness,
  primaryNavPosition,
  siteHeaderTheme,
  utilityNavLinkContent,
  utilityNavSearch,
  siteFooterTheme,
  footerBorderThickness,
  introContent,
  calloutBackground,
  heading,
  snippet,
  linkContent,
  contentLayout,
  bgColor,
  linkStyle,
}) =>
  standardPageBannerTwig({
    site_name: siteName,
    page_title__heading: pageTitle,
    page_title__meta: null,
    site_header__border_thickness: headerBorderThickness,
    site_header__nav_position: primaryNavPosition,
    site_header__theme: siteHeaderTheme,
    site_footer__border_thickness: footerBorderThickness,
    site_footer__theme: siteFooterTheme,
    utility_nav__items: utilityNavData.items,
    primary_nav__items: primaryNavData.items,
    utility_nav__link__content: utilityNavLinkContent,
    utility_nav__link__url: '#',
    utility_nav__search: utilityNavSearch,
    breadcrumbs__items: breadcrumbData.items,
    ...imageData.responsive_images['16x9'],
    intro_content: introContent,
    callout__background_color: calloutBackground,
    ...textWithImageData,
    ...newsCardData,
    banner__heading: heading,
    banner__snippet: snippet,
    banner__link__content: linkContent,
    banner__link__url: bannerData.banner__link__url,
    banner__link__style: linkStyle,
    banner__content__layout: contentLayout,
    banner__content__background: bgColor,
  });
WithBanner.argTypes = {
  heading: {
    name: 'Banner Heading',
    type: 'string',
    defaultValue: bannerData.banner__heading,
  },
  snippet: {
    name: 'Banner Snippet',
    type: 'string',
    defaultValue: bannerData.banner__snippet,
  },
  linkContent: {
    name: 'Banner Link Content',
    type: 'string',
    defaultValue: bannerData.banner__link__content,
  },
  contentLayout: {
    name: 'Banner Content Layout',
    type: 'select',
    options: ['bottom', 'left', 'right'],
    defaultValue: 'bottom',
  },
  bgColor: {
    name: 'Banner Content Background Color',
    type: 'select',
    options: colorPairingsData,
    defaultValue: 'gray-800',
  },
  linkStyle: {
    name: 'Link Style',
    type: 'select',
    options: ['cta', 'text-link'],
    defaultValue: 'cta',
  },
};

export const WithSidebar = ({
  siteName,
  pageTitle,
  headerBorderThickness,
  primaryNavPosition,
  siteHeaderTheme,
  utilityNavLinkContent,
  utilityNavSearch,
  siteFooterTheme,
  footerBorderThickness,
  introContent,
  calloutBackground,
}) =>
  standardPageSidebarTwig({
    site_name: siteName,
    page_title__heading: pageTitle,
    page_title__meta: null,
    site_header__border_thickness: headerBorderThickness,
    site_header__nav_position: primaryNavPosition,
    site_header__theme: siteHeaderTheme,
    site_footer__border_thickness: footerBorderThickness,
    site_footer__theme: siteFooterTheme,
    utility_nav__items: utilityNavData.items,
    primary_nav__items: primaryNavData.items,
    utility_nav__link__content: utilityNavLinkContent,
    utility_nav__link__url: '#',
    utility_nav__search: utilityNavSearch,
    breadcrumbs__items: breadcrumbData.items,
    ...imageData.responsive_images['16x9'],
    intro_content: introContent,
    callout__background_color: calloutBackground,
    ...textWithImageData,
    ...newsCardData,
  });
