// Twig templates
import pageTitleTwig from './yds-page-title.twig';
import dateTimeTwig from '../../01-atoms/date-time/yds-date-time.twig';

// Data files
// import textData from './text/text.yml';

import socialLinksData from '../social-links/social-links.yml';
import './page-title';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Page Title',
  argTypes: {
    meta: {
      name: 'Meta',
      type: 'string',
    },
    prefix: {
      name: 'Page Title Prefix',
      type: 'string',
    },
    socialLinks: {
      name: 'Social Links',
      type: 'boolean',
    },
  },
  args: {
    meta: `<span>By Charlyn Paradis</span>${dateTimeTwig({
      date_time__start: '2022-01-25',
      date_time__format: 'date',
    })}`,
    socialLinks: 'false',
  },
};

export const PageTitle = ({ meta, prefix, socialLinks }) =>
  pageTitleTwig({
    page_title__heading: 'Davis Team Project Wins Award for Research',
    page_title__meta: meta,
    page_title__prefix: prefix,
    page_title__show_social_links: socialLinks ? 'true' : 'false',
    ...socialLinksData,
  });
