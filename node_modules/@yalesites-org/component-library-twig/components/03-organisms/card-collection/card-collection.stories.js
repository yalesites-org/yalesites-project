import cardCollectionTwig from './yds-card-collection.twig';

import postCardData from '../../02-molecules/cards/reference-card/examples/post-card.yml';
import eventCardData from '../../02-molecules/cards/reference-card/examples/event-card.yml';
import imageData from '../../01-atoms/images/image/image.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Card Collection',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
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

export const PostCardCollection = ({
  heading,
  collectionType,
  featured,
  withImages,
}) => {
  const items = featured ? [1, 2, 3] : [1, 2, 3, 4];

  return cardCollectionTwig({
    card_example_type: 'post',
    card_collection__type: collectionType,
    card_collection__heading: heading,
    card_collection__featured: featured ? 'true' : 'false',
    card_collection__with_images: withImages ? 'true' : 'false',
    card_collection__cards: items,
    ...postCardData,
    ...imageData.responsive_images['3x2'],
  });
};
PostCardCollection.argTypes = {
  heading: {
    name: 'Heading',
    type: 'string',
    defaultValue: 'Post Card Grid Heading',
  },
};

export const EventCardCollection = ({
  heading,
  collectionType,
  featured,
  withImages,
}) => {
  const items = featured ? [1, 2, 3] : [1, 2, 3, 4];

  return cardCollectionTwig({
    card_example_type: 'event',
    format: 'Online',
    card_collection__type: collectionType,
    card_collection__heading: heading,
    card_collection__featured: featured ? 'true' : 'false',
    card_collection__with_images: withImages ? 'true' : 'false',
    card_collection__cards: items,
    ...eventCardData,
    ...imageData.responsive_images['3x2'],
  });
};
EventCardCollection.argTypes = {
  heading: {
    name: 'Heading',
    type: 'string',
    defaultValue: 'Event Card Grid Heading',
  },
};
