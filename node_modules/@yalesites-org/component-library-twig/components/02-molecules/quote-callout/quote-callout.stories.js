import quoteCalloutTwig from './yds-quote-callout.twig';

import quoteCalloutData from './quote-callout.yml';

// Image atom component - generic images for demo
import imageData from '../../01-atoms/images/image/image.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Quotes/Quote Callout',
  argTypes: {
    quote: {
      name: 'Quote',
      type: 'string',
      defaultValue: quoteCalloutData.quote_callout__quote,
    },
    attribution: {
      name: 'Attribution',
      type: 'string',
      defaultValue: quoteCalloutData.quote_callout__attribution,
    },
    style: {
      name: 'Style',
      options: ['bar', 'quote'],
      type: 'select',
      defaultValue: 'bar',
    },
    quoteAlignment: {
      name: 'Quote Alignment',
      options: ['left', 'right'],
      type: 'select',
      defaultValue: 'left',
    },
    accentColor: {
      name: 'Component Theme (dial)',
      options: ['one', 'two', 'three'],
      type: 'select',
      defaultValue: 'one',
    },
    quoteImage: {
      name: 'Quote Image',
      options: ['with-image', 'no-image'],
      type: 'select',
      defaultValue: 'no-image',
    },
  },
};

export const quoteCallout = ({
  style,
  accentColor,
  quote,
  attribution,
  quoteAlignment,
  quoteImage,
}) => `
  ${quoteCalloutTwig({
    quote_callout__quote: quoteCalloutData.quote_callout__quote,
    quote_callout__attribution: quoteCalloutData.quote_callout__attribution,
  })}
  ${quoteCalloutTwig({
    quote_callout__quote: quoteCalloutData.quote_callout__quote,
    quote_callout__style: 'bar',
    quote_callout__quote_alignment: 'right',
  })}
  ${quoteCalloutTwig({
    quote_callout__quote: quoteCalloutData.quote_callout__quote,
    quote_callout__attribution: quoteCalloutData.quote_callout__attribution,
    quote_callout__style: 'quote',
    quote_callout__quote_alignment: 'left',
  })}
  ${quoteCalloutTwig({
    quote_callout__quote: quoteCalloutData.quote_callout__quote,
    quote_callout__attribution: quoteCalloutData.quote_callout__attribution,
    quote_callout__style: 'quote',
    quote_callout__quote_alignment: 'right',
  })}
  ${quoteCalloutTwig({
    quote_callout__quote: quoteCalloutData.quote_callout__quote,
    quote_callout__attribution: quoteCalloutData.quote_callout__attribution,
    quote_callout__style: 'image',
    quote_callout__quote_alignment: 'left',
    quote_callout__quote_image: 'with-image',
    ...imageData.responsive_images['1x1'],
  })}
  <div style="--color-quote-callout-accent: var(--color-${accentColor})">
    <h2>Playground</h2>
    <p>Use the StoryBook controls to see the quote below implement the available variations and colors.</p>
    ${quoteCalloutTwig({
      quote_callout__quote: quote,
      quote_callout__attribution: attribution,
      quote_callout__style: style,
      quote_callout__accent_theme: accentColor,
      quote_callout__quote_alignment: quoteAlignment,
      quote_callout__quote_image: quoteImage,
      ...imageData.responsive_images['1x1'],
    })}
  </div>
`;
