import contentImageTwig from './yds-content-image.twig';

import imageData from '../../01-atoms/images/image/image.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Image',
  argTypes: {
    caption: {
      name: 'Caption',
      type: 'string',
    },
    width: {
      name: 'Component Width',
      type: 'select',
      options: ['content', 'highlight', 'site', 'max'],
    },
  },
  args: {
    caption: 'This is the caption for the 16:9 image above.',
    width: 'content',
  },
};

export const ContentImage = ({ caption, width }) =>
  contentImageTwig({
    ...imageData.responsive_images['16x9'],
    content_image__caption: caption,
    content_image__width: width,
  });
