import tokens from '@yalesites-org/tokens/build/json/tokens.json';
import getGlobalThemes from '../../00-tokens/colors/color-global-themes';
import ctaTwig from './cta/yds-cta.twig';
import linkTwig from './text-link/yds-text-link.twig';

import textCopyButton from './text-copy-button/yds-text-copy-button.twig';

import './text-link/yds-text-link';
import './text-copy-button/yds-text-copy-button';

import themeExamplesTwig from './cta/_yds-cta-examples.twig';

const siteGlobalThemes = { themes: tokens['global-themes'] };
const componentThemes = { themes: tokens['button-cta-themes'] };
const componentThemeOptions = Object.keys(tokens['button-cta-themes']);
const siteGlobalThemeOptions = getGlobalThemes(tokens['global-themes']);

/**
 * Storybook Definition.
 */
export default {
  title: 'Atoms/Controls',
};

const ctaText = 'Call to action';
export const Cta = ({ componentTheme }) => `
  <h2>Filled</h2>
  <div class="cta-group">
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: 'https://google.com',
      cta__component_theme: componentTheme,
    })}
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__radius: 'soft',
      cta__component_theme: componentTheme,
    })}
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: 'https://google.com/test.pdf',
      cta__radius: 'pill',
      cta__component_theme: componentTheme,
    })}
  </div>
  <h2>Outline</h2>
  <div class="cta-group">
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__style: 'outline',
      cta__component_theme: componentTheme,
    })}
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__radius: 'soft',
      cta__style: 'outline',
      cta__component_theme: componentTheme,
    })}
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__radius: 'pill',
      cta__style: 'outline',
      cta__component_theme: componentTheme,
    })}
  </div>
  <h2>Outline Weights</h2>
  <div class="cta-group">
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__style: 'outline',
      cta__outline_weight: '1',
      cta__component_theme: componentTheme,
    })}
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__style: 'outline',
      cta__outline_weight: '2',
      cta__component_theme: componentTheme,
    })}
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__style: 'outline',
      cta__outline_weight: '4',
      cta__component_theme: componentTheme,
    })}
  </div>
  <h2>Hover Effects</h2>
  <div class="cta-group">
    ${ctaTwig({
      cta__content: 'Fade',
      cta__href: '#',
      cta__component_theme: componentTheme,
    })}
    ${ctaTwig({
      cta__content: 'Rise',
      cta__hover_style: 'rise',
      cta__href: '#',
      cta__component_theme: componentTheme,
    })}
    ${ctaTwig({
      cta__content: 'Wipe',
      cta__hover_style: 'wipe',
      cta__href: '#',
      cta__component_theme: componentTheme,
    })}
  </div>
  <div class="cta-group">
    ${ctaTwig({
      cta__content: 'Fade',
      cta__style: 'outline',
      cta__href: '#',
      cta__component_theme: componentTheme,
    })}
    ${ctaTwig({
      cta__content: 'Rise',
      cta__style: 'outline',
      cta__hover_style: 'rise',
      cta__href: '#',
      cta__component_theme: componentTheme,
    })}
    ${ctaTwig({
      cta__content: 'Wipe',
      cta__style: 'outline',
      cta__hover_style: 'wipe',
      cta__href: '#',
      cta__component_theme: componentTheme,
    })}
  </div>
`;

Cta.argTypes = {
  componentTheme: {
    name: 'Component Theme (dial)',
    options: componentThemeOptions,
    type: 'select',
    defaultValue: 'one',
  },
};

export const textLink = () => `
  ${linkTwig({
    link__url: 'http://localhost:6006',
    link__content: 'This is a default link',
  })}<br />
  ${linkTwig({
    link__url: '#',
    link__content: 'This is a "no-underline" link',
    link__style: 'no-underline',
    link__type: 'normal',
  })}<br />
  ${linkTwig({
    link__url: 'https://google.com',
    link__content: 'This is an "external" link',
    link__style: 'underline-with-icon',
    link__type: 'external',
  })}
  ${linkTwig({
    link__url: '#',
    link__content: 'This is a "new target" link',
    link__style: 'underline-with-icon',
    link__type: 'target-blank',
    link__attributes: {
      target: '_blank',
    },
  })}
  ${linkTwig({
    link__url: 'https://google.com/download.pdf',
    link__content: 'This is a "download" link',
    link__style: 'underline-with-icon',
    link__type: 'download',
  })}
  ${linkTwig({
    link__url: '#',
    link__content: 'This is a link with chevron',
    link__style: 'underline-with-icon',
    link__type: 'with-chevron',
    link__url_type: 'chevron',
  })}
  ${linkTwig({
    link__url: '#',
    link__content: 'This is a long link without animated underlines',
    link__style: 'no-underline-animation',
  })}<br/>
`;

export const CtaExamples = ({ globalTheme, componentTheme }) =>
  themeExamplesTwig({
    ...siteGlobalThemes,
    ...componentThemes,
    site_global__theme: globalTheme,
    example_content: `
    <h2>Filled</h2>
    <div class="cta-group">
      ${ctaTwig({
        cta__content: ctaText,
        cta__href: 'https://google.com',
        cta__component_theme: componentTheme,
      })}
      ${ctaTwig({
        cta__content: ctaText,
        cta__href: 'https://google.com/download.pdf',
        cta__radius: 'soft',
        cta__component_theme: componentTheme,
      })}
      ${ctaTwig({
        cta__content: ctaText,
        cta__href: '#',
        cta__radius: 'pill',
        cta__component_theme: componentTheme,
      })}
    </div>
    <h2>Outline</h2>
    <div class="cta-group">
      ${ctaTwig({
        cta__content: ctaText,
        cta__href: '#',
        cta__style: 'outline',
        cta__component_theme: componentTheme,
      })}
      ${ctaTwig({
        cta__content: ctaText,
        cta__href: '#',
        cta__radius: 'soft',
        cta__style: 'outline',
        cta__component_theme: componentTheme,
      })}
      ${ctaTwig({
        cta__content: ctaText,
        cta__href: '#',
        cta__radius: 'pill',
        cta__style: 'outline',
        cta__component_theme: componentTheme,
      })}
    </div>
    <h2>Outline Weights</h2>
    <div class="cta-group">
      ${ctaTwig({
        cta__content: ctaText,
        cta__href: '#',
        cta__style: 'outline',
        cta__outline_weight: '1',
        cta__component_theme: componentTheme,
      })}
      ${ctaTwig({
        cta__content: ctaText,
        cta__href: '#',
        cta__style: 'outline',
        cta__outline_weight: '2',
        cta__component_theme: componentTheme,
      })}
      ${ctaTwig({
        cta__content: ctaText,
        cta__href: '#',
        cta__style: 'outline',
        cta__outline_weight: '4',
        cta__component_theme: componentTheme,
      })}
    </div>
    <h2>Hover Effects</h2>
    <div class="cta-group">
      ${ctaTwig({
        cta__content: 'Fade',
        cta__href: '#',
        cta__component_theme: componentTheme,
      })}
      ${ctaTwig({
        cta__content: 'Rise',
        cta__hover_style: 'rise',
        cta__href: '#',
        cta__component_theme: componentTheme,
      })}
      ${ctaTwig({
        cta__content: 'Wipe',
        cta__hover_style: 'wipe',
        cta__href: '#',
        cta__component_theme: componentTheme,
      })}
    </div>
    <div class="cta-group">
      ${ctaTwig({
        cta__content: 'Fade',
        cta__style: 'outline',
        cta__href: '#',
        cta__component_theme: componentTheme,
      })}
      ${ctaTwig({
        cta__content: 'Rise',
        cta__style: 'outline',
        cta__hover_style: 'rise',
        cta__href: '#',
        cta__component_theme: componentTheme,
      })}
      ${ctaTwig({
        cta__content: 'Wipe',
        cta__style: 'outline',
        cta__hover_style: 'wipe',
        cta__href: '#',
        cta__component_theme: componentTheme,
      })}
    </div>
    `,
  });

CtaExamples.argTypes = {
  globalTheme: {
    name: 'Global Theme (lever)',
    options: siteGlobalThemeOptions,
    type: 'select',
    defaultValue: 'one',
  },
  componentTheme: {
    name: 'Component Theme (dial)',
    options: componentThemeOptions,
    type: 'select',
    defaultValue: 'one',
  },
};

export const LinkExamples = ({ globalTheme, componentTheme }) =>
  themeExamplesTwig({
    ...siteGlobalThemes,
    ...componentThemes,
    site_global__theme: globalTheme,
    example_content: `
      <div class="link-group" data-cta-theme="${componentTheme}">
      ${linkTwig({
        link__url: 'http://localhost:6006',
        link__content: 'This is a default link',
      })}<br />
      ${linkTwig({
        link__url: '#',
        link__content: 'This is a "no-underline" link',
        link__style: 'no-underline',
        link__type: 'normal',
      })}<br />
      ${linkTwig({
        link__url: 'https://google.com',
        link__content: 'This is an "external" link',
        link__style: 'underline-with-icon',
        link__type: 'external',
      })}
      ${linkTwig({
        link__url: '#',
        link__content: 'This is a "new target" link',
        link__style: 'underline-with-icon',
        link__type: 'target-blank',
        link__attributes: {
          target: '_blank',
        },
      })}
      ${linkTwig({
        link__url: 'https://google.com/download.pdf',
        link__content: 'This is a "download" link',
        link__style: 'underline-with-icon',
        link__type: 'download',
      })}
      ${linkTwig({
        link__url: '#',
        link__content: 'This is a link with chevron',
        link__style: 'underline-with-icon',
        link__type: 'with-chevron',
        link__url_type: 'chevron',
      })}
      ${linkTwig({
        link__url: '#',
        link__content: 'This is a long link without animated underlines',
        link__style: 'no-underline-animation',
      })}<br/>
      </div>
      `,
  });

export const textCopyButtonExamples = ({ globalTheme, componentTheme }) =>
  themeExamplesTwig({
    ...siteGlobalThemes,
    ...componentThemes,
    site_global__theme: globalTheme,
    example_content: `
      ${textCopyButton({
        text_copy_button__pre_text: 'person@example.com',
        text_copy_button__content: '(copy)',
        text_copy_button__component_theme: componentTheme,
      })}
    `,
  });

textCopyButtonExamples.argTypes = {
  globalTheme: {
    name: 'Global Theme (lever)',
    options: siteGlobalThemeOptions,
    type: 'select',
    defaultValue: 'one',
  },
  componentTheme: {
    name: 'Component Theme (dial)',
    options: componentThemeOptions,
    type: 'select',
    defaultValue: 'one',
  },
};
