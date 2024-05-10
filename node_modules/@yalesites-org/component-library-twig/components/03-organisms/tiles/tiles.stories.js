import tokens from '@yalesites-org/tokens/build/json/tokens.json';
// get global themes as `label` : `key` values to pass into options as array.
import getGlobalThemes from '../../00-tokens/colors/color-global-themes';

// tiles twig
import tilesTwig from './yds-tiles.twig';

// Stat default data
import tilesData from './tiles.yml';

// Image atom component - generic images for demo
import imageData from '../../01-atoms/images/image/image.yml';

// Get global theme options
const siteGlobalThemeOptions = getGlobalThemes(tokens['global-themes']);

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Tiles',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    globalTheme: {
      name: 'Global Theme (lever)',
      options: siteGlobalThemeOptions,
      type: 'select',
      defaultValue: 'one',
    },
    presentationStyle: {
      name: 'Presentation Style',
      options: ['number', 'icon', 'text-only'],
      type: 'select',
      defaultValue: 'number',
    },
    alignment: {
      name: 'Alignment',
      options: ['left', 'right'],
      type: 'select',
      defaultValue: 'left',
    },
    verticalAlignment: {
      name: 'Vertical Alignment',
      options: ['top', 'bottom'],
      type: 'select',
      defaultValue: 'top',
    },
    gridCount: {
      name: 'Grid Count',
      options: ['two', 'three', 'four'],
      type: 'select',
      defaultValue: 'three',
    },
    image: {
      name: 'With image',
      type: 'boolean',
      defaultValue: false,
    },
  },
};

export const Tiles = ({
  globalTheme,
  presentationStyle,
  alignment,
  verticalAlignment,
  gridCount,
  image,
}) => {
  return `
    <div class="wrap-for-global-theme" data-global-theme="${globalTheme}">
      ${tilesTwig({
        site_global__theme: globalTheme,
        tiles__alignment: alignment,
        tiles__vertical_alignment: verticalAlignment,
        tiles__presentation_style: presentationStyle,
        tiles__grid_count: gridCount,
        tiles__with__image: image ? 'true' : 'false',
        ...tilesData,
        ...imageData.responsive_images['1x1'],
      })}
    </div>
    `;
};
