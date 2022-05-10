// Twig templates
import pageTitleTwig from './page-title.twig';
import dateTimeTwig from '../../01-atoms/date-time/date-time.twig';

// Data files
// import textData from './text/text.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Page Title',
  argTypes: {
    meta: {
      name: 'Meta',
      type: 'string',
      defaultValue: `<span>By Charlyn Paradis</span>${dateTimeTwig({
        date_time__start: '2022-01-25',
      })}`,
    },
  },
};

export const PageTitle = ({ meta }) =>
  pageTitleTwig({
    page_title__heading: 'Davis Team Project Wins Award for Research',
    page_title__meta: meta,
  });
