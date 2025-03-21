// Twig templates
import inlineMessageTwig from './yds-inline-message.twig';

// Data files

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Inline Message',
  argTypes: {
    type: {
      name: 'Type',
      type: 'select',
      options: ['general', 'alert'],
    },
    heading: {
      name: 'Heading',
      type: 'string',
    },
    content: {
      name: 'Content',
      type: 'string',
    },
    themeColor: {
      name: 'Component Theme (dial)',
      options: ['one', 'two', 'three', 'four', 'five'],
      type: 'select',
    },
    linkContent: {
      name: 'Link Content',
      type: 'string',
    },
    linkUrl: {
      name: 'Link URL',
      type: 'string',
    },
  },
  args: {
    type: 'general',
    heading: 'This is a general message heading',
    content: 'This is a general message content',
    themeColor: 'one',
    linkContent: 'This is a link',
    linkUrl: '#',
  },
};

export const InlineMessage = ({
  type,
  heading,
  content,
  themeColor,
  linkContent,
  linkUrl,
}) =>
  inlineMessageTwig({
    inline_message__heading: heading,
    inline_message__content: content,
    inline_message__type: type,
    inline_message__theme: themeColor,
    inline_message__link__content: linkContent,
    inline_message__link__url: linkUrl,
  });
