import tokens from '@yalesites-org/tokens/build/json/tokens.json';
import setAttributes from './config';

// Twig files.
import configTwig from './config.twig';
import ctaTwig from '../01-atoms/controls/cta/yds-cta.twig';

// Data files.
import primaryNavData from '../03-organisms/menu/primary-nav/primary-nav.yml';
import tabsData from '../02-molecules/tabs/tabs.yml';

const layoutOptions = Object.keys(tokens.layout['flex-position']);
const thicknessOptions = Object.keys(tokens.border.thickness);
const widths = Object.keys(tokens.layout.width);
const borderThicknessOptions = Object.keys(tokens.border.thickness);
const siteHeaderThemeOptions = Object.keys(tokens['site-header-themes']);
const siteFooterThemeOptions = Object.keys(tokens['site-footer-themes']);

export default {
  title: 'Config',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    thickness: {
      name: 'Line thickness',
      options: thicknessOptions,
      type: 'select',
      defaultValue: 'hairline',
    },
    dividerColor: {
      name: 'Line color',
      options: ['gray-500', 'blue-yale', 'accent'],
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
    headerBorderThickness: {
      name: 'Header: Border thickness',
      options: borderThicknessOptions,
      type: 'select',
      defaultValue: localStorage.getItem('yds-cl-twig-header-border-thickness'),
    },
    siteFooterTheme: {
      name: 'Footer: Theme',
      options: siteFooterThemeOptions,
      type: 'select',
      defaultValue: localStorage.getItem('yds-cl-twig-site-footer-theme'),
    },
    footerBorderThickness: {
      name: 'Footer: Border thickness',
      options: borderThicknessOptions,
      type: 'select',
      defaultValue: localStorage.getItem('yds-cl-twig-footer-border-thickness'),
    },
    menuVariation: {
      name: 'Menu Variation',
      options: ['mega', 'basic'],
      type: 'select',
      defaultValue: localStorage.getItem('yds-cl-twig-menu-variation'),
    },
  },
};

const intro = `
<p>The controls on this page will affect various components across the component library, and are represented on various stories, like example pages. For example, the "Line thickness" option affects the "Divider" and the "Two Column" components, and may affect more in the future.</p>
${ctaTwig({
  cta__content: 'Reset attributes',
  cta__attributes: { onClick: 'resetAttributes();' },
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
  headerBorderThickness,
  siteFooterTheme,
  footerBorderThickness,
  menuVariation,
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
    'yds-cl-twig-primary-nav-position': primaryNavPosition,
    'yds-cl-twig-site-header-theme': siteHeaderTheme,
    'yds-cl-twig-header-border-thickness': headerBorderThickness,
    'yds-cl-twig-site-footer-theme': siteFooterTheme,
    'yds-cl-twig-footer-border-thickness': footerBorderThickness,
    'yds-cl-twig-menu-variation': menuVariation,
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
    primary_nav__items: primaryNavData.items,
    site_header__border_thickness: headerBorderThickness,
    site_header__nav_position: primaryNavPosition,
    site_header__theme: siteHeaderTheme,
    site_footer__border_thickness: footerBorderThickness,
    site_footer__theme: siteFooterTheme,
    menu__variation: menuVariation,
    ...tabsData,
  })}
  `;
};
