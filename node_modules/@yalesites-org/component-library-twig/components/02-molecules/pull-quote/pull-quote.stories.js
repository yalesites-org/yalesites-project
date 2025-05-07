import pullQuoteTwig from './yds-pull-quote.twig';

import pullQuoteData from './pull-quote.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Quotes/Pull Quote',
  argTypes: {
    quote: {
      name: 'Quote',
      type: 'string',
    },
    attribution: {
      name: 'Attribution',
      type: 'string',
    },
    style: {
      name: 'Style',
      options: ['bar-left', 'bar-right', 'quote-left'],
      type: 'select',
    },
    sectionTheme: {
      name: 'Section Theme',
      type: 'select',
      options: ['default', 'one', 'two', 'three', 'four'],
      control: { type: 'select' },
    },
    accentColor: {
      name: 'Component Theme (dial)',
      options: ['one', 'two', 'three'],
      type: 'select',
      if: { arg: 'sectionTheme', eq: 'default' },
    },
  },
  args: {
    quote: pullQuoteData.pull_quote__quote,
    attribution: pullQuoteData.pull_quote__attribution,
    style: 'bar-left',
    accentColor: 'one',
    sectionTheme: 'default',
  },
};

export const pullQuote = ({
  style,
  accentColor,
  quote,
  attribution,
  sectionTheme,
}) => `
  ${pullQuoteTwig({
    pull_quote__quote: pullQuoteData.pull_quote__quote,
    pull_quote__attribution: pullQuoteData.pull_quote__attribution,
  })}
  ${pullQuoteTwig({
    pull_quote__quote: pullQuoteData.pull_quote__quote,
    pull_quote__style: 'bar-right',
  })}
  ${pullQuoteTwig({
    pull_quote__quote: pullQuoteData.pull_quote__quote,
    pull_quote__attribution: pullQuoteData.pull_quote__attribution,
    pull_quote__style: 'quote-left',
  })}
  <div data-component-has-divider="false" data-component-theme="${sectionTheme}" data-component-width="site" class="yds-layout" data-embedded-components="" data-spotlights-position="first">
    <div class="yds-layout__inner" style="--color-pull-quote-accent: var(--color-${accentColor})">
      <div class="yds-layout__primary">
        <h2>Playground</h2>
        <p>Use the StoryBook controls to see the quote below implement the available variations and colors.</p>

        ${pullQuoteTwig({
          pull_quote__quote: quote,
          pull_quote__attribution: attribution,
          pull_quote__style: style,
          pull_quote__accent_theme: accentColor,
        })}
      </div>
    </div>
  </div>
`;
