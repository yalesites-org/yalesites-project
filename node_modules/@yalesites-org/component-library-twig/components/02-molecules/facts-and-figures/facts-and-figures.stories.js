import tokens from '@yalesites-org/tokens/build/json/tokens.json';
import factsAndFiguresTwig from './yds-facts-and-figures.twig';
import factsAndFiguresData from './facts-and-figures.yml';
import factsAndFiguresIconsData from './facts-and-figures-icons.yml';

const colorPairingsData = Object.keys(tokens['component-themes']);

// Process icon data for Storybook controls
const iconOptions = [
  '- None -',
  ...Object.keys(factsAndFiguresIconsData.icons || {}),
];
/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Facts and Figures',
  argTypes: {
    factsAndFigures: {
      name: 'Fact or Figure',
      type: 'string',
      defaultValue: factsAndFiguresData.facts_and_figures__stat,
    },
    content: {
      name: 'Content',
      type: 'string',
      defaultValue: factsAndFiguresData.facts_and_figures__content,
    },
    presentationStyle: {
      name: 'Presentation Style',
      options: ['basic', 'icon-only'],
      type: 'select',
      defaultValue: 'basic',
    },
    fontStyle: {
      name: 'Font Style',
      options: ['normal', 'numeric-oldstyle'],
      type: 'select',
      defaultValue: 'normal',
    },
    alignment: {
      name: 'Alignment',
      options: ['left', 'center'],
      type: 'select',
      defaultValue: 'left',
    },
    themeColor: {
      name: 'Component Theme (dial)',
      options: colorPairingsData,
      type: 'select',
      defaultValue: 'one',
    },
    iconName: {
      name: 'Icon Selection',
      options: iconOptions,
      type: 'select',
      defaultValue: '- None -',
    },
  },
};

export const FactsAndFigures = ({
  factsAndFigures,
  content,
  presentationStyle,
  fontStyle,
  alignment,
  themeColor,
  iconName,
}) => {
  // Determine if icons should be shown based on icon selection
  const hasIcon = iconName && iconName !== '- None -';

  return `

  <ul class='facts-and-figures__group__wrap' data-facts-and-figures-collection-type="single">
    ${factsAndFiguresTwig({
      facts_and_figures__stat: factsAndFiguresData.facts_and_figures__stat,
      facts_and_figures__content:
        factsAndFiguresData.facts_and_figures__content,
      facts_and_figures__presentation_style: 'basic',
      facts_and_figures__has_icon: 'false',
      facts_and_figures__alignment: 'center',
    })}
    ${factsAndFiguresTwig({
      facts_and_figures__stat: factsAndFiguresData.facts_and_figures__stat,
      facts_and_figures__presentation_style: 'basic',
      facts_and_figures__has_icon: 'true',
      facts_and_figures__alignment: 'left',
    })}
    ${factsAndFiguresTwig({
      facts_and_figures__stat: factsAndFiguresData.facts_and_figures__stat,
      facts_and_figures__content:
        factsAndFiguresData.facts_and_figures__content,
      facts_and_figures__presentation_style: 'basic',
      facts_and_figures__alignment: 'center',
      facts_and_figures__has_icon: 'true',
    })}
  </ul>
  <div class="wrap-for-global-theme" data-global-theme="one">
  <h2>Playground</h2>
    <p>Use the StoryBook controls to see the facts and figures below implement the available variations.</p>
    <ul class='facts-and-figures__group__wrap' data-facts-and-figures-collection-type='single'>
      ${factsAndFiguresTwig({
        facts_and_figures__stat: factsAndFigures,
        facts_and_figures__content: content,
        facts_and_figures__presentation_style: presentationStyle,
        facts_and_figures__font_style: fontStyle,
        facts_and_figures__alignment: alignment,
        facts_and_figures__theme: themeColor,
        facts_and_figures__has_icon: hasIcon ? 'true' : 'false',
        facts_and_figures__icon_name: hasIcon ? iconName : null,
      })}
    </ul>
  </div>
`;
};
