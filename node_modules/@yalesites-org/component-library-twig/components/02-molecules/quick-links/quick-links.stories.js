import quickLinksTwig from './yds-quick-links.twig';

import quickLinksData from './quick-links.yml';

import imageData from '../../01-atoms/images/image/image.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Quick-links',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    heading: {
      name: 'Heading',
      type: 'string',
      defaultValue: quickLinksData.quick_links__heading,
    },
    description: {
      name: 'Description',
      type: 'string',
      defaultValue: quickLinksData.quick_links__description,
    },
    image: {
      name: 'With image',
      type: 'boolean',
      defaultValue: true,
    },
  },
};

export const quickLinks = ({ heading, description, variation, image }) =>
  quickLinksTwig({
    ...quickLinksData,
    ...imageData.responsive_images['16x9'],
    quick_links__heading: heading,
    quick_links__description: description,
    quick_links__variation: variation,
    quick_links__image: image,
  });
