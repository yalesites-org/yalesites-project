import blockWrapperTwig from './yds-block-wrapper.twig';

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Block Wrapper',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    blockContent: {
      name: 'Content',
      type: 'string',
    },
  },
  args: {
    blockContent:
      'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Sit amet consectetur adipiscing elit duis tristique sollicitudin nibh sit. Convallis convallis tellus id interdum velit laoreet id donec. Feugiat in ante metus dictum at. Massa eget egestas purus viverra accumsan in nisl nisi. Sed tempus urna et pharetra. Non nisi est sit amet. Leo urna molestie at elementum eu facilisis sed odio morbi. Sollicitudin aliquam ultrices sagittis orci a scelerisque purus semper eget. Pharetra massa massa ultricies mi. Elementum curabitur vitae nunc sed velit dignissim sodales ut. Semper feugiat nibh sed pulvinar. Scelerisque eleifend donec pretium vulputate sapien nec sagittis aliquam malesuada. Id interdum velit laoreet id donec ultrices tincidunt arcu non. Mollis nunc sed id semper risus in. Placerat in egestas erat imperdiet sed. Tempor commodo ullamcorper a lacus vestibulum sed arcu non odio. In eu mi bibendum neque. Sit amet venenatis urna cursus eget nunc scelerisque.',
  },
};

export const BlockWrapper = ({ blockContent }) => {
  return blockWrapperTwig({ block_wrapper__content: blockContent });
};
