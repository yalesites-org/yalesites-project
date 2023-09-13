// Twig templates
import pageTitleTwig from './yds-page-title.twig';
import dateTimeTwig from '../../01-atoms/date-time/yds-date-time.twig';

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
        date_time__format: 'date',
      })}`,
    },
    prefix: {
      name: 'Page Title Prefix',
      type: 'string',
    },
  },
};

export const PageTitle = ({ meta, prefix }) =>
  pageTitleTwig({
    page_title__heading: 'Davis Team Project Wins Award for Research',
    page_title__meta: meta,
    page_title__prefix: prefix,
  });
