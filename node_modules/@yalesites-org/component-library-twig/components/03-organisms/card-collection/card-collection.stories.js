import cardCollectionTwig from './card-collection.twig';

import newsCardData from '../../02-molecules/cards/news-card/news-card.yml';
import imageData from '../../01-atoms/images/image/image.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Card Collection',
  argTypes: {
    heading: {
      name: 'Heading',
      type: 'string',
      defaultValue: 'News Card Grid Heading',
    },
    collectionType: {
      name: 'Collection Type',
      type: 'select',
      options: ['grid', 'list'],
      defaultValue: 'grid',
    },
    featured: {
      name: 'Featured',
      type: 'boolean',
      defaultValue: true,
    },
    withImages: {
      name: 'With Images',
      type: 'boolean',
      defaultValue: true,
    },
  },
};

export const NewsCardCollection = ({
  heading,
  collectionType,
  featured,
  withImages,
}) => {
  const items = featured ? [1, 2, 3] : [1, 2, 3, 4];

  return cardCollectionTwig({
    card_example_type: 'news',
    card_collection__type: collectionType,
    card_collection__heading: heading,
    card_collection__featured: featured ? 'true' : 'false',
    card_collection__with_images: withImages ? 'true' : 'false',
    card_collection__cards: items,
    ...newsCardData,
    ...imageData.responsive_images['3x2'],
  });
};
