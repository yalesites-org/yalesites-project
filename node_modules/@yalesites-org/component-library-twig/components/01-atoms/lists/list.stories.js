import listTwig from './yds-list.twig';
import listTagsTwig from './taxonomy/yds-tags-list.twig';
import listCategoriesTwig from './taxonomy/yds-categories-list.twig';

import listData from './list.yml';
import listTagsData from './taxonomy/tags-list.yml';
import listCategoriesData from './taxonomy/categories-list.yml';

/**
 * Storybook Definition.
 */
export default { title: 'Atoms/Lists' };

export const UnorderedList = () => `
  <div class="text-field">
    ${listTwig({ list__items: listData.unordered__list__items })}
  </div>
`;
export const OrderedList = () => `
<div class="text-field">
  ${listTwig({ list__items: listData.ordered__list__items, list__type: 'ol' })}
</div>
`;

export const TagsList = (args) => listTagsTwig({ ...listTagsData, ...args });

export const CategoriesList = (args) =>
  listCategoriesTwig({ ...listCategoriesData, ...args });
