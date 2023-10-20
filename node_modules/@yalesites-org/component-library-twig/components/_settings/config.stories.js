import tokens from '@yalesites-org/tokens/build/json/tokens.json';
import setAttributes from './config';
import getGlobalThemes from '../00-tokens/colors/color-global-themes';

// Twig files.
import configTwig from './config.twig';
import ctaTwig from '../01-atoms/controls/cta/yds-cta.twig';

// Data files.
import primaryNavData from '../03-organisms/menu/primary-nav/primary-nav.yml';
import tabsData from '../02-molecules/tabs/tabs.yml';

const layoutOptions = ['left', 'center'];
const thicknessOptions = Object.keys(tokens.border.thickness);
const widths = Object.keys(tokens.layout.width);
const borderThicknessOptions = Object.keys(tokens.border.thickness);
const siteHeaderThemeOptions = Object.keys(tokens['site-header-themes']);
const siteFooterThemeOptions = Object.keys(tokens['site-footer-themes']);
const siteGlobalThemeOptions = getGlobalThemes(tokens['global-themes']);
const siteAnimationOptions = ['artistic', 'default'];
const siteFooterVariations = ['basic', 'mega'];
const siteHeaderAccents = [
  'one',
  'two',
  'three',
  'four',
  'five',
  'six',
  'seven',
  'eight',
];
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

export default {
  title: 'Config',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    globalTheme: {
      name: 'Global Theme (lever)',
      options: siteGlobalThemeOptions,
      type: 'select',
      defaultValue: localStorage.getItem('yds-cl-twig-global-theme'),
    },
    allowAnimatedItems: {
      name: 'Site Animation Theme',
      options: siteAnimationOptions,
      type: 'select',
      defaultValue: 'default',
    },
    thickness: {
      name: 'Line thickness',
      options: thicknessOptions,
      type: 'select',
      defaultValue: 'hairline',
    },
    dividerColor: {
      name: 'Line color',
      options: ['gray-500', 'blue-yale', 'basic-brown-gray'],
      type: 'select',
      defaultValue: 'gray-500',
    },
    dividerWidth: {
      name: 'Divider width',
      options: [...widths],
      type: 'select',
      defaultValue: '100',
    },
    dividerPosition: {
      name: 'Divider position',
      options: layoutOptions,
      type: 'select',
      defaultValue: 'center',
    },
    actionColor: {
      name: 'Action color',
      options: ['blue-yale', 'basic-black'],
      type: 'select',
      defaultValue: 'blue-yale',
    },
    menuVariation: {
      name: 'Menu Variation',
      options: ['mega', 'basic'],
      type: 'select',
      defaultValue: localStorage.getItem('yds-cl-twig-menu-variation'),
    },
    primaryNavPosition: {
      name: 'Navigation position',
      options: ['left', 'center', 'right'],
      type: 'select',
      defaultValue: localStorage.getItem('yds-cl-twig-primary-nav-position'),
    },
    siteHeaderTheme: {
      name: 'Header: Theme',
      options: siteHeaderThemeOptions,
      type: 'select',
      defaultValue: localStorage.getItem('yds-cl-twig-site-header-theme'),
    },
    siteHeaderAccent: {
      name: 'Header Accent Color (dial)',
      options: siteHeaderAccents,
      type: 'select',
      defaultValue: localStorage.getItem('yds-cl-twig-site-header-accent'),
    },
    headerBorderThickness: {
      name: 'Header: Border thickness',
      options: borderThicknessOptions,
      type: 'select',
      defaultValue: localStorage.getItem('yds-cl-twig-header-border-thickness'),
    },
    siteFooterVariation: {
      name: 'Footer: Variation',
      options: siteFooterVariations,
      type: 'select',
      defaultValue: localStorage.getItem('yds-cl-twig-site-footer-variation'),
    },
    siteFooterTheme: {
      name: 'Footer: Theme',
      options: siteFooterThemeOptions,
      type: 'select',
      defaultValue: localStorage.getItem('yds-cl-twig-site-footer-theme'),
    },
    siteFooterAccent: {
      name: 'Footer Accent Color (dial)',
      options: siteFooterAccents,
      type: 'select',
      defaultValue: localStorage.getItem('yds-cl-twig-site-footer-accent'),
    },
    footerBorderThickness: {
      name: 'Footer: Border thickness',
      options: borderThicknessOptions,
      type: 'select',
      defaultValue: localStorage.getItem('yds-cl-twig-footer-border-thickness'),
    },
  },
};

const intro = `
<p>The controls on this page will affect various components across the component library, and are represented on various stories, like example pages. For example, the "Line thickness" option affects the "Divider" and the "Two Column" components, and may affect more in the future.</p>
${ctaTwig({
  cta__content: 'Reset attributes',
  cta__attributes: { onClick: 'resetAttributes();' },
  cta__component_theme: 'one',
})}
`;

export const GlobalConfig = ({
  dividerPosition,
  thickness,
  dividerColor,
  dividerWidth,
  actionColor,
  primaryNavPosition,
  siteHeaderTheme,
  siteHeaderAccent,
  headerBorderThickness,
  siteFooterTheme,
  siteFooterAccent,
  footerBorderThickness,
  menuVariation,
  globalTheme,
  allowAnimatedItems,
  siteFooterVariation,
}) => {
  const root = document.documentElement;
  const customProperties = {
    '--thickness-theme-divider': `var(--size-thickness-${thickness})`,
    '--width-theme-divider': `var(--layout-width-${dividerWidth})`,
    '--color-theme-divider': `var(--color-${dividerColor})`,
    '--position-theme-divider': `var(--layout-flex-position-${dividerPosition})`,
    '--color-theme-action': `var(--color-${actionColor})`,
  };
  const dataAttributes = {
    'yds-cl-twig-global-theme': globalTheme,
    'yds-cl-twig-menu-variation': menuVariation,
    'yds-cl-twig-primary-nav-position': primaryNavPosition,
    'yds-cl-twig-site-header-theme': siteHeaderTheme,
    'yds-cl-twig-site-header-accent': siteHeaderAccent,
    'yds-cl-twig-header-border-thickness': headerBorderThickness,
    'yds-cl-twig-site-footer-theme': siteFooterTheme,
    'yds-cl-twig-site-footer-accent': siteFooterAccent,
    'yds-cl-twig-footer-border-thickness': footerBorderThickness,
    'yds-cl-twig-animate-items': allowAnimatedItems,
    'yds-cl-twig-site-footer-variation': siteFooterVariation,
  };

  // Set properties that are stored as custom properties to the root element.
  // @TODO: Ideally these would also live in local storage so that they persist
  // page refreshes.
  Object.entries(customProperties).forEach((entry) => {
    const [key, value] = entry;
    root.style.setProperty(key, value);
  });

  // Set properties that are stored as data-attributes to localStorage.
  setAttributes(dataAttributes);

  return `
  <script>
  console.log(allowAnimatedItems);
    const resetAttributes = () => {
      Object.keys(localStorage).forEach((key) => {
        if (key.substring(0, 12) === 'yds-cl-twig-') {
          localStorage.removeItem(key);
        }
      });

      location.reload();
    };
  </script>

  ${configTwig({
    site_name: 'Global Settings',
    config_page__intro: intro,
    site_animate_components: allowAnimatedItems,
    primary_nav__items: primaryNavData.items,
    site_global__theme: globalTheme,
    menu__variation: menuVariation,
    site_header__border_thickness: headerBorderThickness,
    site_header__nav_position: primaryNavPosition,
    site_header__theme: siteHeaderTheme,
    site_header__accent: siteHeaderAccent,
    site_footer__variation: siteFooterVariation,
    site_footer__border_thickness: footerBorderThickness,
    site_footer__theme: siteFooterTheme,
    site_footer__accent: siteFooterAccent,
    ...tabsData,
  })}
  `;
};
