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
      defaultValue: searchResultData.search_result__title,
    },
    highlighted: {
      name: 'Search Results Highlighted',
      type: 'string',
      defaultValue: searchResultData.search_result__highlighted,
    },
    teaser: {
      name: 'Search Results Teaser',
      type: 'string',
      defaultValue: searchResultData.search_result__teaser,
    },
  },
};

export const SearchResult = ({ heading, highlighted, teaser }) =>
  searchResultTwig({
    search_result__teaser: teaser,
    search_result__title: heading,
    search_result__url: '#',
    search_result__highlighted: highlighted,
    breadcrumbs__items: breadcrumbData.items,
  });
