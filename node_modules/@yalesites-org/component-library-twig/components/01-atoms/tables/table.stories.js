// Markup.
import tableTwig from './example-tables.twig';

import './table';

/**
 * Storybook Definition.
 */
export default {
  title: 'Atoms/Table',
  argTypes: {
    sectionTheme: {
      name: 'Section Theme',
      type: 'select',
      options: ['default', 'one', 'two', 'three', 'four'],
      control: { type: 'select' },
    },
  },
  args: {
    sectionTheme: 'default',
  },
};

export const Table = ({ sectionTheme }) => `
  ${tableTwig()}
  <div data-component-has-divider="false" data-component-theme="${sectionTheme}" data-component-width="site" class="yds-layout" data-embedded-components="" data-spotlights-position="first">
    <div class="yds-layout__inner">
      <div class="yds-layout__primary">
        <h2>Playground</h2>
        <p>Use the StoryBook controls to see the table below implement the available variations and colors.</p>
        ${tableTwig()}
      </div>
    </div>
  </div>
`;
