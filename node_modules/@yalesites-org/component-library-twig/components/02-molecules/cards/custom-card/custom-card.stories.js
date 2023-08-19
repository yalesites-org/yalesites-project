// Custom card twig file
import customCardTwig from './yds-custom-card.twig';

// Custom card default data
import customCardData from './custom-card.yml';

// Image atom component - generic images for demo
import imageData from '../../../01-atoms/images/image/image.yml';

// JavaScript to handle full-card linking
import './yds-custom-card';

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
      defaultValue: customCardData.custom_card__heading,
    },
    snippet: {
      name: 'Snippet',
      type: 'string',
      defaultValue: customCardData.custom_card__snippet,
    },
    withImage: {
      name: 'With Image',
      type: 'boolean',
      defaultValue: true,
    },
    featured: {
      name: 'Featured',
      type: 'boolean',
      defaultValue: true,
    },
  },
};

export const customCard = ({ heading, snippet, withImage, featured }) => `
  <div class='custom-card-collection' data-component-width='site' data-collection-featured="${featured}">
    <div class='custom-card-collection__inner'>
      <ul class='custom-card-collection__cards'>
        ${customCardTwig({
          ...imageData.responsive_images['3x2'],
          custom_card__heading: heading,
          custom_card__snippet: snippet,
          custom_card__url: '#',
          custom_card__image: withImage ? 'true' : 'false',
        })}
      </ul>
    </div>
  </div>
`;
