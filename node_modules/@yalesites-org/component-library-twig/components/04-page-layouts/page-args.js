import tokens from '@yalesites-org/tokens/build/json/tokens.json';

const borderThicknessOptions = Object.keys(tokens.border.thickness);
const primaryNavPositions = Object.keys(tokens.layout['flex-position']);
const siteHeaderThemeOptions = Object.keys(tokens['site-header-themes']);
const siteFooterThemeOptions = Object.keys(tokens['site-footer-themes']);

const argTypes = {
  siteName: {
    name: 'Site Name',
    type: 'string',
    defaultValue: 'Department of Chemistry',
  },
  headerBorderThickness: {
    name: 'Header: Border thickness',
    options: borderThicknessOptions,
    type: 'select',
    defaultValue: '8',
  },
  primaryNavPosition: {
    name: 'Header: Primary nav position',
    options: primaryNavPositions,
    type: 'select',
    defaultValue: 'right',
  },
  utilityNavLinkContent: {
    name: 'Header: Utility nav link text',
    type: 'string',
    defaultValue: null,
  },
  utilityNavSearch: {
    name: 'Header: Search',
    type: 'boolean',
    defaultValue: false,
  },
  siteHeaderTheme: {
    name: 'Header: Theme',
    options: siteHeaderThemeOptions,
    type: 'select',
    defaultValue: 'white',
  },
  footerBorderThickness: {
    name: 'Footer: Border thickness',
    options: borderThicknessOptions,
    type: 'select',
    defaultValue: '8',
  },
  siteFooterTheme: {
    name: 'Footer: Theme',
    options: siteFooterThemeOptions,
    type: 'select',
    defaultValue: 'blue-yale',
  },
  pageTitle: {
    name: 'Page Title',
    type: 'string',
    defaultValue: 'Davis Team Project Wins Award for Research',
  },
  meta: {
    name: 'Meta',
    type: 'string',
    defaultValue: `<span>By Charlyn Paradis</span><time class="date-time" datetime="2022-01-25">January 25, 2022</time>`,
  },
};

export default argTypes;
