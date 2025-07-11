import tabs from './yds-tabs.twig';
import tabData from './tabs.yml';
import './yds-tabs';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Tabs',
  argTypes: {
    componentTheme: {
      name: 'Component Theme',
      type: 'select',
      options: ['one', 'two', 'three'],
      control: { type: 'select' },
    },
    sectionTheme: {
      name: 'Section Theme',
      type: 'select',
      options: ['default', 'one', 'two', 'three', 'four'],
      control: { type: 'select' },
    },
  },
  args: {
    componentTheme: 'one',
    sectionTheme: 'default',
  },
};

export const Tabs = ({ componentTheme, sectionTheme }) => `
  ${tabs({ ...tabData })}
  <div data-component-has-divider="false" data-component-theme="${sectionTheme}" data-component-width="site" class="yds-layout" data-embedded-components="" data-spotlights-position="first">
    <div class="yds-layout__inner">
      <div class="yds-layout__primary">
        <h2>Playground</h2>
        <p>Use the StoryBook controls to see the tabs below implement the available variations and colors.</p>
        ${tabs({
          ...tabData,
          tabs__id: '123',
          tabs__theme: componentTheme,
        })}
      </div>
    </div>
  </div>
`;
