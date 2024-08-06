import tokens from '@yalesites-org/tokens/build/json/tokens.json';

// Shared Storybook args.
import argTypes from '../../04-page-layouts/cl-page-args';

// Twig files.
import standardPageTwig from './standard-page.twig';
import standardPageBannerTwig from './standard-page-with-banner.twig';
import standardPageSidebarTwig from './standard-page-with-sidebar.twig';
import standardPageQuickLinksTwig from './standard-page-with-quicklinks.twig';
import standardPageShortTwig from './standard-page-short.twig';
import standardPageSpotlightsTwig from './standard-page-spotlights.twig';

// Data files.
import utilityNavData from '../../03-organisms/menu/utility-nav/utility-nav.yml';
import primaryNavData from '../../03-organisms/menu/primary-nav/primary-nav.yml';
import breadcrumbData from '../../03-organisms/menu/breadcrumbs/breadcrumbs.yml';
import imageData from '../../01-atoms/images/image/image.yml';
import textWithImageData from '../../02-molecules/text-with-image/text-with-image.yml';
import bannerData from '../../02-molecules/banner/banner.yml';
import grandHeroData from '../../02-molecules/banner/grand-hero.yml';
import referenceCardData from '../../02-molecules/cards/reference-card/examples/post-card.yml';
import customCardData from '../../02-molecules/cards/custom-card/custom-card.yml';
import socialLinksData from '../../02-molecules/social-links/social-links.yml';
import quickLinksData from '../../02-molecules/quick-links/quick-links.yml';
import videoData from '../../02-molecules/video/video.yml';
import accordionData from '../../02-molecules/accordion/accordion.yml';
import tabData from '../../02-molecules/tabs/tabs.yml';
import mediaGridData from '../../03-organisms/galleries/media-grid/media-grid.yml';
import contentSpotlightPortraitData from '../../02-molecules/content-spotlight-portrait/content-spotlight-portrait.yml';

// JavaScript.
import '../../00-tokens/layout/yds-layout';

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
        'wrapped-image',
        'text-with-image--focus-image',
        'text-with-image--focus-equal',
        'collection-featured',
        'collection-secondary',
      ],
      type: 'select',
      defaultValue: 'none',
    },
    calloutBackground: {
      name: 'Callout Theme (dial)',
      type: 'select',
      options: ['one', 'two', 'three'],
      defaultValue: 'one',
    },
    pageTitleDisplay: {
      name: 'Page Title Display',
      type: 'select',
      options: ['visible', 'hidden', 'visually-hidden'],
      defaultValue: 'visible',
    },
  },
};

// Basic page
export const Basic = ({
  siteName,
  pageTitle,
  pageTitleDisplay,
  allowAnimatedItems = localStorage.getItem('yds-cl-twig-animate-items'),
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
  siteFooterVariation = localStorage.getItem(
    'yds-cl-twig-site-footer-variation',
  ),
  siteFooterTheme = localStorage.getItem('yds-cl-twig-site-footer-theme'),
  siteFooterAccent = localStorage.getItem('yds-cl-twig-site-footer-accent'),
  footerBorderThickness = localStorage.getItem(
    'yds-cl-twig-footer-border-thickness',
  ),
  introContent,
  calloutBackground,
}) =>
  standardPageTwig({
    site_name: siteName,
    page_title__heading: pageTitle,
    page_title__meta: null,
    page_title__display: pageTitleDisplay,
    page_title__additional_classes: [pageTitleDisplay],
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
    site_header__menu__variation: menuVariation,
    utility_nav__link__content: utilityNavLinkContent,
    utility_nav__link__url: '#',
    utility_nav__search: utilityNavSearch,
    breadcrumbs__items: breadcrumbData.items,
    intro_content: introContent,
    callout__background_color: calloutBackground,
    ...textWithImageData,
    ...referenceCardData,
    ...socialLinksData,
    ...imageData.responsive_images['4x3'],
  });

// Short page
export const BasicShort = ({
  siteName,
  pageTitle,
  allowAnimatedItems = localStorage.getItem('yds-cl-twig-animate-items'),
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
  siteFooterVariation = localStorage.getItem(
    'yds-cl-twig-site-footer-variation',
  ),
  siteFooterTheme = localStorage.getItem('yds-cl-twig-site-footer-theme'),
  siteFooterAccent = localStorage.getItem('yds-cl-twig-site-footer-accent'),
  footerBorderThickness = localStorage.getItem(
    'yds-cl-twig-footer-border-thickness',
  ),
  introContent,
  calloutBackground,
}) =>
  standardPageShortTwig({
    site_name: siteName,
    page_title__heading: pageTitle,
    page_title__meta: null,
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
    site_header__menu__variation: menuVariation,
    utility_nav__link__content: utilityNavLinkContent,
    utility_nav__link__url: '#',
    utility_nav__search: utilityNavSearch,
    breadcrumbs__items: breadcrumbData.items,
    ...imageData.responsive_images['4x3'],
    intro_content: introContent,
    callout__background_color: calloutBackground,
    ...textWithImageData,
    ...referenceCardData,
    ...socialLinksData,
  });

// Spotlight page
export const BasicSpotlights = ({
  siteName,
  pageTitle,
  allowAnimatedItems = localStorage.getItem('yds-cl-twig-animate-items'),
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
  siteFooterVariation = localStorage.getItem(
    'yds-cl-twig-site-footer-variation',
  ),
  siteFooterTheme = localStorage.getItem('yds-cl-twig-site-footer-theme'),
  siteFooterAccent = localStorage.getItem('yds-cl-twig-site-footer-accent'),
  footerBorderThickness = localStorage.getItem(
    'yds-cl-twig-footer-border-thickness',
  ),
  calloutBackground,
}) =>
  standardPageSpotlightsTwig({
    site_name: siteName,
    page_title__heading: pageTitle,
    page_title__meta: null,
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
    site_header__menu__variation: menuVariation,
    utility_nav__link__content: utilityNavLinkContent,
    utility_nav__link__url: '#',
    utility_nav__search: utilityNavSearch,
    breadcrumbs__items: breadcrumbData.items,
    callout__background_color: calloutBackground,
    ...textWithImageData,
    ...referenceCardData,
    ...socialLinksData,
    ...contentSpotlightPortraitData,
    ...imageData.responsive_images['2x3'],
  });

// With Banner
export const WithBanner = ({
  siteName,
  pageTitle,
  allowAnimatedItems = localStorage.getItem('yds-cl-twig-animate-items'),
  globalTheme = localStorage.getItem('yds-cl-twig-global-theme'),
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
  menuVariation = localStorage.getItem('yds-cl-twig-menu-variation'),
  introContent,
  calloutBackground,
  heading,
  snippet,
  linkContent,
  contentLayout,
  bgColor,
  linkStyle,
  bannerType,
  videoHeading,
  videoCaption,
  grandHeroOverlayVariation,
  grandHeroSize,
  grandHeroWithVideo,
}) =>
  standardPageBannerTwig({
    site_name: siteName,
    page_title__heading: pageTitle,
    page_title__meta: null,
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
    site_header__menu__variation: menuVariation,
    utility_nav__link__content: utilityNavLinkContent,
    utility_nav__link__url: '#',
    utility_nav__search: utilityNavSearch,
    breadcrumbs__items: breadcrumbData.items,
    intro_content: introContent,
    callout__background_color: calloutBackground,
    ...textWithImageData,
    ...referenceCardData,
    ...customCardData,
    banner_type: bannerType,
    banner__heading: heading,
    banner__snippet: snippet,
    banner__link__content: linkContent,
    banner__link__url: bannerData.banner__link__url,
    banner__link__style: linkStyle,
    banner__content__layout: contentLayout,
    banner__content__background: bgColor,
    grand_hero__heading: heading,
    grand_hero__snippet: snippet,
    grand_hero__link__content: linkContent,
    grand_hero__link__url: grandHeroData.grand_hero__link__url,
    grand_hero__content__background: bgColor,
    grand_hero__overlay_variation: grandHeroOverlayVariation,
    grand_hero__size: grandHeroSize,
    grand_hero__video: grandHeroWithVideo ? 'true' : 'false',
    ...imageData.responsive_images['16x9'],
    ...socialLinksData,
    ...videoData,
    video__heading: videoHeading,
    video__text: videoCaption,
    ...accordionData,
    ...tabData,
    ...mediaGridData,
  });
WithBanner.argTypes = {
  bannerType: {
    name: 'Banner Type',
    type: 'select',
    options: ['action', 'grand-hero'],
    defaultValue: 'grand-hero',
  },
  contentLayout: {
    name: 'Banner Content Layout',
    type: 'select',
    options: ['bottom', 'left', 'right'],
    defaultValue: 'bottom',
  },
  bgColor: {
    name: 'Banner Content Background Color Theme (dial)',
    type: 'select',
    options: colorPairingsData,
    defaultValue: 'one',
  },
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
  linkStyle: {
    name: 'Link Style',
    type: 'select',
    options: ['cta', 'text-link'],
    defaultValue: 'cta',
  },
  grandHeroOverlayVariation: {
    name: 'Grand Hero Content Overlay',
    type: 'select',
    options: ['contained', 'full'],
    defaultValue: 'full',
  },
  grandHeroSize: {
    name: 'Grand Hero Content Size',
    type: 'select',
    options: ['reduced', 'full'],
    defaultValue: 'full',
  },
  grandHeroWithVideo: {
    name: 'Grand Hero With Video',
    type: 'boolean',
    defaultValue: false,
  },
  videoHeading: {
    name: 'Video Heading',
    type: 'string',
    defaultValue: videoData.video__heading,
  },
  videoCaption: {
    name: 'Video Caption',
    type: 'string',
    defaultValue: videoData.video__text,
  },
  ...accordionData,
};

// With sidebar
export const WithSidebar = ({
  siteName,
  pageTitle,
  allowAnimatedItems = localStorage.getItem('yds-cl-twig-animate-items'),
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
  siteFooterVariation = localStorage.getItem(
    'yds-cl-twig-site-footer-variation',
  ),
  siteFooterTheme = localStorage.getItem('yds-cl-twig-site-footer-theme'),
  siteFooterAccent = localStorage.getItem('yds-cl-twig-site-footer-accent'),
  footerBorderThickness = localStorage.getItem(
    'yds-cl-twig-footer-border-thickness',
  ),
  introContent,
  calloutBackground,
}) =>
  standardPageSidebarTwig({
    site_name: siteName,
    page_title__heading: pageTitle,
    page_title__meta: null,
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
    site_header__menu__variation: menuVariation,
    utility_nav__link__content: utilityNavLinkContent,
    utility_nav__link__url: '#',
    utility_nav__search: utilityNavSearch,
    breadcrumbs__items: breadcrumbData.items,
    ...imageData.responsive_images['16x9'],
    intro_content: introContent,
    callout__background_color: calloutBackground,
    ...textWithImageData,
    ...referenceCardData,
    ...socialLinksData,
  });

// With quick links
export const WithQuickLinks = ({
  siteName,
  pageTitle,
  allowAnimatedItems = localStorage.getItem('yds-cl-twig-animate-items'),
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
  siteFooterVariation = localStorage.getItem(
    'yds-cl-twig-site-footer-variation',
  ),
  siteFooterTheme = localStorage.getItem('yds-cl-twig-site-footer-theme'),
  siteFooterAccent = localStorage.getItem('yds-cl-twig-site-footer-accent'),
  footerBorderThickness = localStorage.getItem(
    'yds-cl-twig-footer-border-thickness',
  ),
  heading,
  description,
  image,
  variation,
}) =>
  standardPageQuickLinksTwig({
    site_name: siteName,
    page_title__heading: pageTitle,
    page_title__meta: null,
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
    site_header__menu__variation: menuVariation,
    utility_nav__link__content: utilityNavLinkContent,
    utility_nav__link__url: '#',
    utility_nav__search: utilityNavSearch,
    breadcrumbs__items: breadcrumbData.items,
    ...imageData.responsive_images['16x9'],
    ...referenceCardData,
    ...socialLinksData,
    quick_links__heading: heading,
    quick_links__description: description,
    quick_links__image: image,
    quick_links__variation: variation,
    quick_links__links: quickLinksData.quick_links__links,
  });
WithQuickLinks.argTypes = {
  heading: {
    name: 'Quick Links Heading',
    type: 'string',
    defaultValue: quickLinksData.quick_links__heading,
  },
  description: {
    name: 'Quick Links Description',
    type: 'string',
    defaultValue: quickLinksData.quick_links__description,
  },
  image: {
    name: 'With image',
    type: 'boolean',
    defaultValue: true,
  },
  variation: {
    name: 'Quick Links Variation',
    type: 'select',
    options: ['promotional', 'subtle'],
    defaultValue: 'promotional',
  },
};
