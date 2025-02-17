// Twig templates
import searchResultTwig from './yds-search-result.twig';

// Data files
import searchResultData from './search-result.yml';
import breadcrumbData from './breadcrumbs.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Search Result',
  argTypes: {
    heading: {
      name: 'Heading',
      type: 'string',
    },
    highlighted: {
      name: 'Search Results Highlighted',
      type: 'string',
    },
    teaser: {
      name: 'Search Results Teaser',
      type: 'string',
    },
    contentType: {
      name: 'Search Results Content Type',
      type: 'string',
      defaultValue: searchResultData.search_result__content_type,
    },
    isCas: {
      name: 'Is CAS',
      type: 'boolean',
    },
  },
  args: {
    heading: searchResultData.search_result__title,
    highlighted: searchResultData.search_result__highlighted,
    teaser: searchResultData.search_result__teaser,
  },
};

export const SearchResult = ({
  heading,
  highlighted,
  teaser,
  contentType,
  isCas,
}) =>
  searchResultTwig({
    search_result__teaser: teaser,
    search_result__title: heading,
    search_result__url: '#',
    search_result__highlighted: highlighted,
    breadcrumbs__items: breadcrumbData.items,
    search_result__content_type: contentType,
    search_result__prefix__icon: isCas ? 'lock-solid' : '',
    is_cas: isCas,
  });
