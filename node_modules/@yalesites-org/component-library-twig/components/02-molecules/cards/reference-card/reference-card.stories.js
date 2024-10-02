import referenceCardTwig from './examples/_card--examples.twig';

import referenceCardData from './examples/post-card.yml';
import referenceProfileCardData from './examples/profile-card.yml';
import imageData from '../../../01-atoms/images/image/image.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Cards',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    heading: {
      name: 'Heading',
      type: 'string',
      defaultValue: referenceCardData.reference_card__heading,
    },
    snippet: {
      name: 'Snippet',
      type: 'string',
      defaultValue: referenceCardData.reference_card__snippet,
    },
    collectionType: {
      name: 'Collection Type',
      type: 'select',
      options: ['grid', 'list', 'condensed', 'single'],
      defaultValue: 'grid',
    },
    featured: {
      name: 'Featured',
      type: 'boolean',
      defaultValue: true,
    },
    showCategories: {
      name: 'Show Categories/Affiliations',
      type: 'boolean',
      defaultValue: false,
    },
    categories: {
      name: 'Categories',
      type: 'array',
      defaultValue: referenceCardData.reference_card__categories,
      if: { arg: 'showCategories' },
    },
    showPronouns: {
      name: 'Show Pronouns',
      type: 'boolean',
      defaultValue: false,
    },
    showTags: {
      name: 'Show Tags',
      type: 'boolean',
      defaultValue: false,
    },
    showThumbnail: {
      name: 'Show Thumbnail',
      type: 'boolean',
      defaultValue: true,
    },
    tags: {
      name: 'Tags',
      type: 'array',
      defaultValue: referenceCardData.reference_card__tags,
      if: { arg: 'showTags' },
    },
    withImage: {
      name: 'With Image',
      type: 'boolean',
      defaultValue: true,
    },
  },
};

export const PostCard = ({
  date,
  heading,
  snippet,
  collectionType,
  featured,
  withImage,
  categories,
  showCategories,
  tags,
  showTags,
  showThumbnail,
}) => `
<div class='card-collection' data-component-width='site' data-collection-type='${collectionType}' data-collection-featured="${featured}">
  <div class='card-collection__inner'>
    <ul class='card-collection__cards'>
      ${referenceCardTwig({
        card_collection__source_type: 'post',
        card_collection__type: collectionType,
        ...imageData.responsive_images['3x2'],
        reference_card__date: date,
        reference_card__heading: heading,
        reference_card__snippet: snippet,
        reference_card__featured: featured ? 'true' : 'false',
        reference_card__image: withImage ? 'true' : 'false',
        reference_card__url: referenceCardData.reference_card__url,
        reference_card__categories: categories,
        show_categories: showCategories,
        reference_card__tags: tags,
        show_tags: showTags,
        show_thumbnail: showThumbnail,
      })}
    </ul>
  </div>
</div>
`;
PostCard.argTypes = {
  date: {
    name: 'Date',
    type: 'string',
    defaultValue: referenceCardData.reference_card__date,
  },
};

export const EventCard = ({
  format,
  heading,
  snippet,
  collectionType,
  featured,
  withImage,
  primaryCTAContent,
  primaryCTAURL,
  secondaryCTAContent,
  secondaryCTAURL,
  multiDayEvent,
  headingPrefix,
  categories,
  showCategories,
  tags,
  showTags,
  showThumbnail,
}) => `
<div class='card-collection' data-component-width='site' data-collection-type='${collectionType}' data-collection-featured="${featured}">
  <div class='card-collection__inner'>
    <ul class='card-collection__cards'>
      ${referenceCardTwig({
        card_collection__source_type: 'event',
        card_collection__type: collectionType,
        ...imageData.responsive_images['3x2'],
        format,
        reference_card__heading: heading,
        reference_card__prefix: headingPrefix,
        reference_card__snippet: snippet,
        reference_card__featured: featured ? 'true' : 'false',
        reference_card__image: withImage ? 'true' : 'false',
        reference_card__url: referenceCardData.reference_card__url,
        reference_card__cta_primary__href: primaryCTAURL,
        reference_card__cta_primary__content: primaryCTAContent,
        reference_card__cta_secondary__href: secondaryCTAURL,
        reference_card__cta_secondary__content: secondaryCTAContent,
        multi_day_event: multiDayEvent,
        reference_card__categories: categories,
        show_categories: showCategories,
        reference_card__tags: tags,
        show_tags: showTags,
        show_thumbnail: showThumbnail,
      })}
    </ul>
  </div>
</div>
`;
EventCard.argTypes = {
  format: {
    name: 'Format',
    control: 'select',
    options: ['In-person', 'Online', 'Hybrid'],
    defaultValue: 'In-person',
  },
  headingPrefix: {
    name: 'Heading Prefix',
    type: 'string',
    defaultValue: '',
  },
  primaryCTAContent: {
    name: 'Primary CTA Content',
    type: 'string',
    defaultValue: 'Buy Tickets',
  },
  primaryCTAURL: {
    name: 'Primary CTA URL',
    type: 'string',
    defaultValue: 'https://yale.edu',
  },
  secondaryCTAContent: {
    name: 'Secondary CTA Content',
    type: 'string',
    defaultValue: 'Add to Calendar',
  },
  secondaryCTAURL: {
    name: 'Secondary CTA URL',
    type: 'string',
    defaultValue: 'https://yale.edu',
  },
  multiDayEvent: {
    name: 'Multi-day Event',
    type: 'boolean',
    defaultValue: false,
  },
};

export const ProfileCard = ({
  collectionType,
  featured,
  withImage,
  categories,
  showCategories,
  showPronouns,
  tags,
  showTags,
  showThumbnail,
}) => `
<div class='card-collection' data-component-width='site' data-collection-source='profile' data-collection-type='${collectionType}' data-collection-featured="${featured}">
  <div class='card-collection__inner'>
    <ul class='card-collection__cards'>
      ${referenceCardTwig({
        card_collection__source_type: 'profile',
        card_collection__type: collectionType,
        ...imageData.responsive_images['1x1'],
        reference_card__featured: featured ? 'true' : 'false',
        reference_card__image: withImage ? 'true' : 'false',
        reference_card__heading:
          referenceProfileCardData.reference_card__heading,
        reference_card__heading_extra:
          referenceProfileCardData.reference_card__pronouns,
        reference_card__subheading:
          referenceProfileCardData.reference_card__subheading,
        reference_card__snippet:
          referenceProfileCardData.reference_card__snippet,
        reference_card__url: referenceProfileCardData.reference_card__url,
        reference_card__categories: categories,
        show_categories: showCategories,
        show_pronouns: showPronouns,
        reference_card__tags: tags,
        show_tags: showTags,
        show_thumbnail: showThumbnail,
      })}
    </ul>
  </div>
</div>
`;
