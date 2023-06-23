import tokens from '@yalesites-org/tokens/build/json/tokens.json';
// get global themes as `label` : `key` values to pass into options as array.
import getGlobalThemes from '../../00-tokens/colors/color-global-themes';

// Custom card collection twig
import customCardCollectionTwig from './yds-custom-card-collection.twig';

// Custom card default data
import customCardData from '../../02-molecules/cards/custom-card/custom-card.yml';

// Image atom component - generic images for demo
import imageData from '../../01-atoms/images/image/image.yml';

// Get global theme options
const siteGlobalThemeOptions = getGlobalThemes(tokens['global-themes']);

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Card Collection/Custom Card Collection',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    globalTheme: {
      name: 'Global Theme (lever)',
      options: siteGlobalThemeOptions,
      type: 'select',
      defaultValue: 'one',
    },
    customCardCollectionHeading: {
      name: 'Collection Heading',
      type: 'string',
      defaultValue: 'Custom Card Collection Heading',
    },
    featured: {
      name: 'Featured',
      type: 'boolean',
      defaultValue: true,
    },
    withImage: {
      name: 'With Images',
      type: 'boolean',
      defaultValue: true,
    },
  },
};

export const customCardCollection = ({
  heading,
  snippet,
  url,
  customCardCollectionHeading,
  withImage,
  featured,
  globalTheme,
}) => {
  const items = featured ? [1, 2, 3] : [1, 2, 3, 4];

  return `
    <div class="wrap-for-global-theme" data-global-theme="${globalTheme}">
      ${customCardCollectionTwig({
        site_global__theme: globalTheme,
        custom_card_collection__heading: customCardCollectionHeading,
        custom_card__heading: heading,
        custom_card__snippet: snippet,
        custom_card__url: url,
        custom_card__image: withImage ? 'true' : 'false',
        custom_card_collection__featured: featured ? 'true' : 'false',
        custom_card_collection__cards: items,
        ...customCardData,
        ...imageData.responsive_images['3x2'],
      })}
    </div>
  `;
};
