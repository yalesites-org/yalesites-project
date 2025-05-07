// Markup.
import breadcrumbsTwig from './yds-breadcrumbs.twig';

// Data.
import breadcrumbsData from './breadcrumbs.yml';

// JavaScript.
import './yds-breadcrumbs';

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Menu/Breadcrumbs',
  argTypes: {
    limitItems: {
      name: 'Limit Items',
      type: 'boolean',
    },
    trailLevel: {
      name: 'Trail Level',
      type: 'number',
      if: {
        arg: 'limitItems',
        truthy: true,
      },
    },
  },
  args: {
    limitItems: false,
    trailLevel: 2,
  },
};

export const Breadcrumbs = ({ trailLevel }) =>
  breadcrumbsTwig({ ...breadcrumbsData, breadcrumbs__trail_level: trailLevel });
