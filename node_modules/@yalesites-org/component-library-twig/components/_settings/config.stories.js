import tokens from '@yalesites-org/tokens/build/json/tokens.json';
import setAttributes from './config';

// Twig files.
import configTwig from './config.twig';
import ctaTwig from '../01-atoms/controls/cta/yds-cta.twig';

// Data files.
import primaryNavData from '../03-organisms/menu/primary-nav/primary-nav.yml';
import tabsData from '../02-molecules/tabs/tabs.yml';
import imageData from '../01-atoms/images/image/image.yml';

const layoutOptions = ['left', 'center'];
const thicknessOptions = Object.keys(tokens.border.thickness);
const widths = Object.keys(tokens.layout.width);
const borderThicknessOptions = Object.keys(tokens.border.thickness);
const siteHeaderThemeOptions = Object.keys(tokens['site-header-themes']);
const siteFooterThemeOptions = Object.keys(tokens['site-footer-themes']);
const siteAnimationOptions = ['artistic', 'default'];
const siteFooterVariations = ['basic', 'mega'];

// Storing the header/footer accent colors here for now instead of adding them to our Tokens repository. These may move
// in the future.
const themesOneToEight = [
  'one',
  'two',
  'three',
  'four',
  'five',
  'six',
  'seven',
  'eight',
];
const siteHeaderAccents = themesOneToEight;
const siteFooterAccents = themesOneToEight;

const valueConfig = {
  allowAnimatedItems: {
    storage: 'yds-cl-twig-animate-items',
    defaultValue: 'default',
  },
  thickness: {
    defaultValue: 'hairline',
  },
  dividerColor: {
    defaultValue: 'gray-500',
  },
  dividerWidth: {
    defaultValue: '100',
  },
  dividerPosition: {
    defaultValue: 'center',
  },
  actionColor: {
    defaultValue: 'blue-yale',
  },
  menuVariation: {
    storage: 'yds-cl-twig-menu-variation',
    defaultValue: 'mega',
  },
  primaryNavPosition: {
    storage: 'yds-cl-twig-primary-nav-position',
    defaultValue: 'left',
  },
  siteHeaderTheme: {
    storage: 'yds-cl-twig-site-header-theme',
    defaultValue: 'one',
  },
  siteHeaderAccent: {
    storage: 'yds-cl-twig-site-header-accent',
    defaultValue: 'one',
  },
  siteHeaderImage: {
    defaultValue: false,
  },
  siteHeaderSiteNameImage: {
    defaultValue: false,
  },
  headerBorderThickness: {
    storage: 'yds-cl-twig-header-border-thickness',
    defaultValue: 'hairline',
  },
  siteFooterVariation: {
    storage: 'yds-cl-twig-site-footer-variation',
    defaultValue: 'basic',
  },
  siteFooterTheme: {
    storage: 'yds-cl-twig-site-footer-theme',
    defaultValue: 'one',
  },
  siteFooterAccent: {
    storage: 'yds-cl-twig-site-footer-accent',
    defaultValue: 'one',
  },
  footerBorderThickness: {
    storage: 'yds-cl-twig-footer-border-thickness',
    defaultValue: 'hairline',
  },
};

// Returns a function that knows about the config to look up values.
const getLookupFor = (config) => {
  return (key) => {
    const { storage, defaultValue } = config[key];

    if (storage) {
      const value = localStorage.getItem(storage);

      if (value && value !== undefined && value !== 'undefined') {
        return value;
      }
    }
    return defaultValue;
  };
};

const getValueFor = getLookupFor(valueConfig);

const config = {
  allowAnimatedItems: getValueFor('allowAnimatedItems'),
  thickness: getValueFor('thickness'),
  dividerColor: getValueFor('dividerColor'),
  dividerWidth: getValueFor('dividerWidth'),
  dividerPosition: getValueFor('dividerPosition'),
  actionColor: getValueFor('actionColor'),
  menuVariation: getValueFor('menuVariation'),
  primaryNavPosition: getValueFor('primaryNavPosition'),
  siteHeaderTheme: getValueFor('siteHeaderTheme'),
  siteHeaderAccent: getValueFor('siteHeaderAccent'),
  siteHeaderImage: getValueFor('siteHeaderImage'),
  siteHeaderSiteNameImage: getValueFor('siteHeaderSiteNameImage'),
  headerBorderThickness: getValueFor('headerBorderThickness'),
  siteFooterVariation: getValueFor('siteFooterVariation'),
  siteFooterTheme: getValueFor('siteFooterTheme'),
  siteFooterAccent: getValueFor('siteFooterAccent'),
  footerBorderThickness: getValueFor('footerBorderThickness'),
};

export default {
  title: 'Config',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    allowAnimatedItems: {
      name: 'Site: Animation Theme',
      options: siteAnimationOptions,
      type: 'select',
    },
    thickness: {
      name: 'Site: Line thickness',
      options: thicknessOptions,
      type: 'select',
    },
    dividerColor: {
      name: 'Site: Line color',
      options: ['gray-500', 'blue-yale', 'basic-brown-gray'],
      type: 'select',
    },
    dividerWidth: {
      name: 'Site: Divider width',
      options: [...widths],
      type: 'select',
    },
    dividerPosition: {
      name: 'Site: Divider position',
      options: layoutOptions,
      type: 'select',
    },
    actionColor: {
      name: 'Action Color',
      options: ['blue-yale', 'basic-black'],
      type: 'select',
    },
    menuVariation: {
      name: 'Site: Menu Variation',
      options: ['mega', 'basic', 'focus'],
      type: 'select',
    },
    primaryNavPosition: {
      name: 'Site: Navigation Position',
      options: ['left', 'center', 'right'],
      type: 'select',
    },
    siteHeaderTheme: {
      name: 'Header: Theme',
      options: siteHeaderThemeOptions,
      type: 'select',
    },
    siteHeaderAccent: {
      name: 'Header: Accent Color (dial)',
      options: siteHeaderAccents,
      type: 'select',
    },
    siteHeaderImage: {
      name: 'Header: With Image',
      type: 'boolean',
    },
    siteHeaderSiteNameImage: {
      name: 'Header: Site Name Is An Image',
      type: 'boolean',
    },
    headerBorderThickness: {
      name: 'Header: Border Thickness',
      options: borderThicknessOptions,
      type: 'select',
    },
    siteFooterVariation: {
      name: 'Footer: Variation',
      options: siteFooterVariations,
      type: 'select',
    },
    siteFooterTheme: {
      name: 'Footer: Theme',
      options: siteFooterThemeOptions,
      type: 'select',
    },
    siteFooterAccent: {
      name: 'Footer: Accent Color (dial)',
      options: siteFooterAccents,
      type: 'select',
    },
    footerBorderThickness: {
      name: 'Footer: Border thickness',
      options: borderThicknessOptions,
      type: 'select',
    },
  },
  args: config,
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
  siteHeaderImage,
  siteHeaderSiteNameImage,
  headerBorderThickness,
  siteFooterTheme,
  siteFooterAccent,
  footerBorderThickness,
  menuVariation,
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
    window.resetAttributes = () => {
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
    site_header__branding_link: 'https://www.yale.edu',
    site_header__menu__variation: menuVariation,
    site_header__background_image: siteHeaderImage,
    site_header__site_name_is_image: siteHeaderSiteNameImage,
    site_header__border_thickness: headerBorderThickness,
    site_header__nav_position: primaryNavPosition,
    site_header__theme: siteHeaderTheme,
    site_header__accent: siteHeaderAccent,
    site_footer__variation: siteFooterVariation,
    site_footer__border_thickness: footerBorderThickness,
    site_footer__theme: siteFooterTheme,
    site_footer__accent: siteFooterAccent,
    ...tabsData,
    ...imageData.responsive_images['16x9'],
  })}
  `;
};
