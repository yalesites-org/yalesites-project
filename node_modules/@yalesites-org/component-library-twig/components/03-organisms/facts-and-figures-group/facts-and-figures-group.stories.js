import tokens from '@yalesites-org/tokens/build/json/tokens.json';

// facts_and_figures__group twig
import factsAndFiguresGroupTwig from './yds-facts-and-figures-group.twig';

// Stat default data
import factsAndFiguresGroupData from './facts-and-figures-group.yml';

// Icon data for individual facts and figures
import factsAndFiguresIconsData from '../../02-molecules/facts-and-figures/facts-and-figures-icons.yml';

// Image atom component - generic images for demo
import imageData from '../../01-atoms/images/image/image.yml';

const colorPairingsData = Object.keys(tokens['component-themes']);

// Process icon data for Storybook controls
// The goal is to create an object like:
// {
//   '- None -': '- None -',
//   'Human Readable Name 1': 'icon-name-1',
//   'Human Readable Name 2': 'icon-name-2',
//   ...
// }
const iconDisplayToValueMap = {
  '- None -': '- None -', // Default option to display 'None' and pass 'None'
};

// Check if factsAndFiguresIconsData.icons exists and is an object
if (
  factsAndFiguresIconsData.icons &&
  typeof factsAndFiguresIconsData.icons === 'object'
) {
  Object.entries(factsAndFiguresIconsData.icons).forEach(
    ([iconName, humanReadableName]) => {
      iconDisplayToValueMap[humanReadableName] = iconName;
    },
  );
}

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Facts and Figures Group',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    themeColor: {
      name: 'Component Theme (dial)',
      options: colorPairingsData,
      type: 'select',
    },
    factsAndFiguresGroupHeading: {
      name: 'Infographic Group Heading',
      type: 'string',
    },
    factsAndFiguresGroupContent: {
      name: 'Infographic Group Content',
      type: 'string',
    },
    factsAndFiguresGroupLink: {
      name: 'Infographic Group Link',
      type: 'string',
    },
    image: {
      name: 'With image',
      type: 'boolean',
    },
    presentationStyle: {
      name: 'Presentation Style',
      options: ['basic', 'icon-only'],
      type: 'select',
    },
    fontStyle: {
      name: 'Font Style',
      options: ['normal', 'numeric-oldstyle'],
      type: 'select',
    },
    columnCount: {
      name: 'Column Count',
      options: ['two', 'three', 'four'],
      type: 'select',
      defaultValue: 'three',
    },
    alignment: {
      name: 'Alignment',
      options: ['left', 'center'],
      type: 'select',
    },
    iconName: {
      name: 'Icon Selection',
      options: iconDisplayToValueMap,
      type: 'select',
      defaultValue: '- None -',
    },
  },
  args: {
    globalTheme: 'one',
    themeColor: 'one',
    factsAndFiguresGroupHeading:
      factsAndFiguresGroupData.facts_and_figures__group__heading,
    factsAndFiguresGroupContent:
      factsAndFiguresGroupData.facts_and_figures__group__content,
    factsAndFiguresGroupLink:
      factsAndFiguresGroupData.facts_and_figures__group__link__content,
    image: true,
    presentationStyle: 'basic',
    fontStyle: 'normal',
    alignment: 'left',
    iconName: '- None -',
  },
};

export const FactsAndFiguresGroup = ({
  factsAndFiguresGroupHeading,
  factsAndFiguresGroupContent,
  presentationStyle,
  fontStyle,
  columnCount,
  alignment,
  themeColor,
  image,
  iconName,
}) => {
  // Determine if icons should be shown based on icon selection
  const hasIcon = iconName && iconName !== '- None -';

  // Create custom data with the same icon for all items
  const customGroupData = {
    ...factsAndFiguresGroupData,
    facts_and_figures__group:
      factsAndFiguresGroupData.facts_and_figures__group.map((item) => {
        return {
          ...item,
          facts_and_figures__has_icon: hasIcon ? 'true' : 'false',
          facts_and_figures__icon_name: hasIcon ? iconName : null,
          facts_and_figures__presentation_style: presentationStyle,
          facts_and_figures__font_style: fontStyle,
          facts_and_figures__alignment: alignment,
        };
      }),
  };

  return `
    <div class="wrap-for-global-theme">
      ${factsAndFiguresGroupTwig({
        facts_and_figures__group__heading: factsAndFiguresGroupHeading,
        facts_and_figures__group__content: factsAndFiguresGroupContent,
        facts_and_figures__group__has_icon: hasIcon ? 'true' : 'false',
        facts_and_figures__group__grid_count: columnCount,
        facts_and_figures__group__alignment: alignment,
        facts_and_figures__group__presentation_style: presentationStyle,
        facts_and_figures__group__font_style: fontStyle,
        facts_and_figures__group__theme: themeColor,
        facts_and_figures__group__bg_image: image,
        ...customGroupData,
        ...imageData.responsive_images['16x9'],
      })}
    </div>
    `;
};
