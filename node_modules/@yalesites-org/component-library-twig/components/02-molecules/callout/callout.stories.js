import calloutTwig from './callout.twig';

import calloutData from './callout.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Callout',
  argTypes: {
    heading: {
      name: 'Heading',
      type: 'string',
      defaultValue: calloutData.callout__heading,
    },
    text: {
      name: 'Text',
      type: 'string',
      defaultValue: calloutData.callout__text,
    },
    linkText: {
      name: 'Link Text',
      type: 'string',
      defaultValue: calloutData.callout__link__content,
    },
    linkType: {
      name: 'Link Type',
      type: 'select',
      options: ['button', 'link'],
      defaultValue: calloutData.callout__link__type,
    },
    backgroundColor: {
      name: 'Background Color',
      type: 'select',
      options: ['blue-yale', 'gray-700', 'beige'],
      defaultValue: 'blue-yale',
    },
  },
};

export const Callout = ({
  heading,
  text,
  linkText,
  linkType,
  backgroundColor,
}) => `
  <h2>One Callout</h2>
  ${calloutTwig({
    callout__background_color: backgroundColor,
    callouts: [
      {
        callout__heading: heading,
        callout__text: text,
        callout__link__content: linkText,
        callout__link__url: calloutData.callout__link__url,
        callout__link__type: linkType,
      },
    ],
  })}
  <h2>Two Callouts</h2>
  ${calloutTwig({
    callout__background_color: backgroundColor,
    callouts: [
      {
        callout__heading: heading,
        callout__text: text,
        callout__link__content: linkText,
        callout__link__url: calloutData.callout__link__url,
        callout__link__type: linkType,
      },
      {
        callout__heading: calloutData.callout__heading,
        callout__text: calloutData.callout__text,
        callout__link__content: calloutData.callout__link__content,
        callout__link__url: calloutData.callout__link__url,
        callout__link__type: linkType,
      },
    ],
  })}
`;
