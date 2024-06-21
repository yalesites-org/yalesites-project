// Shared Storybook args.
import argTypes, { eventArgTypes } from '../../04-page-layouts/cl-page-args';

// Twig files.
import eventPageTwig from './event-page.twig';
import eventGridPageTwig from './event-grid.twig';
import eventListPageTwig from './event-list.twig';

// Data files.
import utilityNavData from '../../03-organisms/menu/utility-nav/utility-nav.yml';
import primaryNavData from '../../03-organisms/menu/primary-nav/primary-nav.yml';
import breadcrumbData from '../../03-organisms/menu/breadcrumbs/breadcrumbs.yml';
import imageData from '../../01-atoms/images/image/image.yml';
import pagerData from '../../02-molecules/pager/pager-last.yml';
import socialLinksData from '../../02-molecules/social-links/social-links.yml';

// JavaScript.
import '../../00-tokens/layout/yds-layout';

// Utility to convert dates to unix timestamps
const toUnixTimeStamp = (date) => {
  return Math.floor(Date.parse(date) / 1000);
};

/**
 * Storybook Definition.
 */
export default {
  title: 'Page Examples/Events',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    ...argTypes,
    ...eventArgTypes,
    eventPageTitle: {
      name: 'Page Title',
      type: 'string',
      defaultValue:
        'Parlika (2016) film screening + Q&A with film director Sahraa Karimi',
    },
    showBreadcrumbs: {
      name: 'Breadcrumbs',
      type: 'boolean',
      defaultValue: true,
    },
  },
};

export const EventPage = ({
  siteName,
  eventPageTitle,
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
  startDate,
  endDate,
  format,
  address,
  ctaText,
  showBreadcrumbs,
}) =>
  eventPageTwig({
    site_name: siteName,
    event_title__heading: eventPageTitle,
    site_animate_components: allowAnimatedItems,
    site_global__theme: globalTheme,
    site_header__border_thickness: headerBorderThickness,
    site_header__nav_position: primaryNavPosition,
    site_header__theme: siteHeaderTheme,
    site_header__accent: siteHeaderAccent,
    site_footer__border_thickness: footerBorderThickness,
    site_footer__variation: siteFooterVariation,
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
    event_meta__date_start: toUnixTimeStamp(startDate),
    event_meta__date_end: toUnixTimeStamp(endDate),
    event_meta__format: format,
    event_meta__address: address,
    event_meta__cta_primary__content: ctaText,
    event_meta__cta_primary__href: '#',
    event_meta__cta_secondary__content: 'Add to calendar',
    event_meta__cta_secondary__href: '#',
    ...socialLinksData,
    show_breadcrumbs: showBreadcrumbs,
  });
EventPage.argTypes = {
  pageTitle: {
    table: {
      disable: true,
    },
  },
  meta: {
    table: {
      disable: true,
    },
  },
};

export const EventGrid = ({
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
}) =>
  eventGridPageTwig({
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
    ...imageData.responsive_images['3x2'],
    reference_card__heading:
      'BINYA! A celebration of the legacy of Binyavanga Wainaina at Yale',
    reference_card__snippet:
      'A panel celebrating the legacy of author Binyavanga Wainaina.',
    reference_card__url: 'https://google.com',
    reference_card__date: '2022-03-30 13:00',
    format: 'Online',
    ...socialLinksData,
  });
EventGrid.argTypes = {
  meta: {
    table: {
      disable: true,
    },
  },
};

export const EventList = ({
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
}) =>
  eventListPageTwig({
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
    ...imageData.responsive_images['3x2'],
    reference_card__heading:
      'BINYA! A celebration of the legacy of Binyavanga Wainaina at Yale',
    reference_card__snippet:
      'A panel celebrating the legacy of author Binyavanga Wainaina.',
    reference_card__url: '#',
    reference_card__date: '2022-03-30 13:00',
    format: 'Online',
    ...pagerData,
    ...socialLinksData,
  });
EventList.argTypes = {
  meta: {
    table: {
      disable: true,
    },
  },
};
