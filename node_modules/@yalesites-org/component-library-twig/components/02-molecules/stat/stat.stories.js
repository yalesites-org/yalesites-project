import statTwig from './yds-stat.twig';

import statData from './stat.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Stat',
  argTypes: {
    stat: {
      name: 'Stat',
      type: 'string',
      defaultValue: statData.stat__stat,
    },
    content: {
      name: 'Content',
      type: 'string',
      defaultValue: statData.stat__content,
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
    statIcon: {
      name: 'Stat Icon',
      type: 'boolean',
      defaultValue: false,
    },
  },
};

export const Stat = ({
  stat,
  content,
  presentationStyle,
  fontStyle,
  alignment,
  themeColor,
  statIcon,
}) => `

  <ul class='stats__stats' data-stat-collection-type='single'>
    ${statTwig({
      stat__stat: statData.stat__stat,
      stat__content: statData.stat__content,
      stat__presentation_style: 'basic',
      stat__has_icon: 'false',
      stat__alignment: 'center',
    })}
    ${statTwig({
      stat__stat: statData.stat__stat,
      stat__presentation_style: 'basic',
      stat__has_icon: 'true',
      stat__alignment: 'left',
    })}
    ${statTwig({
      stat__stat: statData.stat__stat,
      stat__content: statData.stat__content,
      stat__presentation_style: 'basic',
      stat__alignment: 'center',
      stat__has_icon: 'true',
    })}
  </ul>
  <div class="wrap-for-global-theme" data-global-theme="one">
  <h2>Playground</h2>
    <p>Use the StoryBook controls to see the stat below implement the available variations.</p>
    <ul class='stats__stats' data-stat-collection-type='single'>
      ${statTwig({
        stat__stat: stat,
        stat__content: content,
        stat__presentation_style: presentationStyle,
        stat__font_style: fontStyle,
        stat__alignment: alignment,
        stat__theme: themeColor,
        stat__has_icon: statIcon ? 'true' : 'false',
      })}
    </ul>
  </div>
`;
