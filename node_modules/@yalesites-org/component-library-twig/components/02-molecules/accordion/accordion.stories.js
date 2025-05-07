import accordionTwig from './yds-accordion.twig';
import accordionData from './accordion.yml';

import './yds-accordion';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Accordion',
  argTypes: {
    accordionHeading: {
      name: 'Accordion Heading',
      type: 'string',
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
      options: ['default', 'one', 'two', 'three', 'four', 'five'],
      type: 'select',
    },
    sectionTheme: {
      name: 'Section Theme',
      type: 'select',
      options: ['default', 'one', 'two', 'three', 'four'],
      control: { type: 'select' },
    },
    itemsToDisplay: {
      name: 'Items to Display',
      options: {
        'One Item': 1,
        'Multiple Items': 3,
      },
      type: 'select',
    },
  },
  args: {
    accordionHeading: accordionData.accordion__heading,
    heading: accordionData.accordion__item__heading,
    content: accordionData.accordion__item__content,
    themeColor: 'default',
    sectionTheme: 'default',
    itemsToDisplay: 3,
  },
};

export const Accordion = ({
  accordionHeading,
  heading,
  content,
  themeColor,
  itemsToDisplay,
  sectionTheme,
}) => {
  const accordionItems = Array.from({ length: itemsToDisplay }, (_, index) => ({
    accordion__item__heading:
      index === 0 ? heading : accordionData.accordion__item__heading,
    accordion__item__content:
      index === 0 ? content : accordionData.accordion__item__content,
  }));

  return `
    <div>
      ${accordionTwig({
        accordion__theme: themeColor,
        accordion__heading: accordionHeading,
        accordion__items: [
          {
            accordion__item__heading: heading,
            accordion__item__content: content,
          },
        ],
      })}
    </div>
    <div data-component-has-divider="false" data-component-theme="${sectionTheme}" data-component-width="site" class="yds-layout" data-embedded-components="" data-spotlights-position="first">
    <div class="yds-layout__inner">
      <div class="yds-layout__primary">
        <h2>Playground</h2>
        <p>Use the StoryBook controls to see the accordion below implement the available variations and colors.</p>
        ${accordionTwig({
          accordion__theme: themeColor,
          accordion__heading: accordionHeading,
          accordion__items: accordionItems,
        })}
      </div>
    </div>
  </div>
  `;
};
