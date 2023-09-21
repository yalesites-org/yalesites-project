import tabs from './yds-tabs.twig';

import tabData from './tabs.yml';

import './yds-tabs';

/**
 * Storybook Definition.
 */
export default { title: 'Molecules/Tabs' };

export const Tabs = () => `
  ${tabs(tabData)}
  ${tabs({ ...tabData, tabs__id: '123' })}
`;
