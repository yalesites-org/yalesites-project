import tokens from '@yalesites-org/tokens/build/json/tokens.json';

import ctaTwig from './cta/yds-cta.twig';
import linkTwig from './text-link/yds-text-link.twig';
import textCopyButtonTwig from './text-copy-button/yds-text-copy-button.twig';

import './text-link/yds-text-link';
import './text-copy-button/yds-text-copy-button';

const componentThemeOptions = Object.keys(tokens['button-cta-themes']);

/**
 * Storybook Definition.
 */
export default {
  title: 'Atoms/Controls',
  argTypes: {
    sectionTheme: {
      name: 'Section Theme',
      type: 'select',
      options: ['default', 'one', 'two', 'three', 'four'],
      control: { type: 'select' },
    },
  },
  parameters: {
    controls: {
      sort: 'requiredFirst',
    },
  },
  args: {
    sectionTheme: 'default',
  },
};

const Section = (sectionTheme, component) => `
  <div class="yds-layout" data-component-theme="${sectionTheme}" data-component-width="site">
    <div class="yds-layout__inner">
      <div class="yds-layout__primary">
        ${component}
      </div>
    </div>
  </div>
`;

const ctaText = 'Call to action';
export const Cta = ({ componentTheme, sectionTheme }) =>
  Section(
    sectionTheme,
    `
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
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__radius: 'pill',
      cta__component_theme: componentTheme,
      cta__control_type: 'dropdown',
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
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__radius: 'outline',
      cta__component_theme: componentTheme,
      cta__control_type: 'dropdown',
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
  );

Cta.argTypes = {
  componentTheme: {
    name: 'Component Theme (dial)',
    options: componentThemeOptions,
    type: 'select',
  },
};

Cta.args = {
  componentTheme: 'one',
};

export const textLink = ({ sectionTheme }) =>
  Section(
    sectionTheme,
    `
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
`,
  );

export const textCopyButton = ({ sectionTheme }) =>
  Section(
    sectionTheme,
    textCopyButtonTwig({
      text_copy_button__pre_text: 'person@example.com',
      text_copy_button__content: '(copy)',
      text_copy_button__component_theme: 'two',
    }),
  );
