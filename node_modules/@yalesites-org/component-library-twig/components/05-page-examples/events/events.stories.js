// Shared Storybook args.
import argTypes, { eventArgTypes } from '../../04-page-layouts/page-args';

// Twig files.
import eventPageTwig from './event-page.twig';

// Data files.
import utilityNavData from '../../03-organisms/menu/utility-nav/utility-nav.yml';
import primaryNavData from '../../03-organisms/menu/primary-nav/primary-nav.yml';
import breadcrumbData from '../../03-organisms/menu/breadcrumbs/breadcrumbs.yml';
import imageData from '../../01-atoms/images/image/image.yml';

// JavaScript.
import '../../00-tokens/layout/layout';

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
  },
};

export const EventPage = ({
  siteName,
  eventPageTitle,
  headerBorderThickness,
  primaryNavPosition,
  siteHeaderTheme,
  utilityNavLinkContent,
  utilityNavSearch,
  siteFooterTheme,
  footerBorderThickness,
  startDate,
  endDate,
  format,
  address,
  ctaText,
}) =>
  eventPageTwig({
    site_name: siteName,
    page_title__heading: eventPageTitle,
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
    ...imageData.responsive_images['4x3'],
    event_meta__date_start: startDate,
    event_meta__date_end: endDate,
    event_meta__format: format,
    event_meta__address: address,
    event_meta__cta_primary__content: ctaText,
    event_meta__cta_primary__href: '#',
    event_meta__cta_secondary__content: 'Add to calendar',
    event_meta__cta_secondary__href: '#',
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
