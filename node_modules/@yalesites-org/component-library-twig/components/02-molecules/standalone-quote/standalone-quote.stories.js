import standaloneQuoteTwig from './yds-standalone-quote.twig';

import standaloneQuoteData from './standalone-quote.yml';

// Image atom component - generic images for demo
import imageData from '../../01-atoms/images/image/image.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Quotes/Standalone Quote',
  argTypes: {
    quote: {
      name: 'Quote',
      type: 'string',
      defaultValue: standaloneQuoteData.standalone_quote__quote,
    },
    attribution: {
      name: 'Attribution',
      type: 'string',
      defaultValue: standaloneQuoteData.standalone_quote__attribution,
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

export const standaloneQuote = ({
  style,
  accentColor,
  quote,
  attribution,
  quoteAlignment,
  quoteImage,
}) => `
  ${standaloneQuoteTwig({
    standalone_quote__quote: standaloneQuoteData.standalone_quote__quote,
    standalone_quote__attribution:
      standaloneQuoteData.standalone_quote__attribution,
  })}
  ${standaloneQuoteTwig({
    standalone_quote__quote: standaloneQuoteData.standalone_quote__quote,
    standalone_quote__style: 'bar',
    standalone_quote__quote_alignment: 'right',
  })}
  ${standaloneQuoteTwig({
    standalone_quote__quote: standaloneQuoteData.standalone_quote__quote,
    standalone_quote__attribution:
      standaloneQuoteData.standalone_quote__attribution,
    standalone_quote__style: 'quote',
    standalone_quote__quote_alignment: 'left',
  })}
  ${standaloneQuoteTwig({
    standalone_quote__quote: standaloneQuoteData.standalone_quote__quote,
    standalone_quote__attribution:
      standaloneQuoteData.standalone_quote__attribution,
    standalone_quote__style: 'quote',
    standalone_quote__quote_alignment: 'right',
  })}
  ${standaloneQuoteTwig({
    standalone_quote__quote: standaloneQuoteData.standalone_quote__quote,
    standalone_quote__attribution:
      standaloneQuoteData.standalone_quote__attribution,
    standalone_quote__style: 'image',
    standalone_quote__quote_alignment: 'left',
    standalone_quote__quote_image: 'with-image',
    ...imageData.responsive_images['1x1'],
  })}
  <div style="--color-standalone-quote-accent: var(--color-${accentColor})">
    <h2>Playground</h2>
    <p>Use the StoryBook controls to see the quote below implement the available variations and colors.</p>
    ${standaloneQuoteTwig({
      standalone_quote__quote: quote,
      standalone_quote__attribution: attribution,
      standalone_quote__style: style,
      standalone_quote__accent_theme: accentColor,
      standalone_quote__quote_alignment: quoteAlignment,
      standalone_quote__quote_image: quoteImage,
      ...imageData.responsive_images['1x1'],
    })}
  </div>
`;
