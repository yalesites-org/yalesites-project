import linkGroupTwig from './yds-link-group.twig';

import linkGroupData from './link-group.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Link group',
  argTypes: {
    heading: {
      name: 'Heading',
      type: 'string',
    },
  },
  args: {
    heading: linkGroupData.link_group__heading,
  },
};

export const linkGroup = ({ heading }) =>
  linkGroupTwig({
    ...linkGroupData,
    link_group__heading: heading,
  });
