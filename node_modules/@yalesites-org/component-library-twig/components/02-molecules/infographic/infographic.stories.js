import infographicTwig from './yds-infographic.twig';

import infographicData from './infographic.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Infographic',
  argTypes: {
    infographic: {
      name: 'infographic',
      type: 'string',
      defaultValue: infographicData.infographic__stat,
    },
    content: {
      name: 'Content',
      type: 'string',
      defaultValue: infographicData.infographic__content,
    },
    presentationStyle: {
      name: 'Presentation Style',
      options: ['basic', 'icon-only'],
      type: 'select',
      defaultValue: 'basic',
    },
    fontStyle: {
      name: 'Font Style',
      options: ['normal', 'numeric-oldstyle'],
      type: 'select',
      defaultValue: 'normal',
    },
    alignment: {
      name: 'Alignment',
      options: ['left', 'center'],
      type: 'select',
      defaultValue: 'left',
    },
    themeColor: {
      name: 'Component Theme (dial)',
      options: ['one', 'two', 'three'],
      type: 'select',
      defaultValue: 'one',
    },
    infographicIcon: {
      name: 'infographic Icon',
      type: 'boolean',
      defaultValue: false,
    },
  },
};

export const Infographic = ({
  infographic,
  content,
  presentationStyle,
  fontStyle,
  alignment,
  themeColor,
  infographicIcon,
}) => `

  <ul class='infographic__group__wrap' data-infographic-collection-type='single'>
    ${infographicTwig({
      infographic__stat: infographicData.infographic__stat,
      infographic__content: infographicData.infographic__content,
      infographic__presentation_style: 'basic',
      infographic__has_icon: 'false',
      infographic__alignment: 'center',
    })}
    ${infographicTwig({
      infographic__stat: infographicData.infographic__stat,
      infographic__presentation_style: 'basic',
      infographic__has_icon: 'true',
      infographic__alignment: 'left',
    })}
    ${infographicTwig({
      infographic__stat: infographicData.infographic__stat,
      infographic__content: infographicData.infographic__content,
      infographic__presentation_style: 'basic',
      infographic__alignment: 'center',
      infographic__has_icon: 'true',
    })}
  </ul>
  <div class="wrap-for-global-theme" data-global-theme="one">
  <h2>Playground</h2>
    <p>Use the StoryBook controls to see the infographic below implement the available variations.</p>
    <ul class='infographic__group__wrap' data-infographic-collection-type='single'>
      ${infographicTwig({
        infographic__stat: infographic,
        infographic__content: content,
        infographic__presentation_style: presentationStyle,
        infographic__font_style: fontStyle,
        infographic__alignment: alignment,
        infographic__theme: themeColor,
        infographic__has_icon: infographicIcon ? 'true' : 'false',
      })}
    </ul>
  </div>
`;
