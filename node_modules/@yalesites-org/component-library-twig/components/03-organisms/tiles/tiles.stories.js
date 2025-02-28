// tiles twig
import tilesTwig from './yds-tiles.twig';

// Stat default data
import tilesData from './tiles.yml';

// Image atom component - generic images for demo
import imageData from '../../01-atoms/images/image/image.yml';

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Tiles',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    presentationStyle: {
      name: 'Presentation Style',
      options: ['heading', 'icon', 'text-only'],
      type: 'select',
    },
    alignment: {
      name: 'Alignment',
      options: ['left', 'center', 'right'],
      type: 'select',
    },
    verticalAlignment: {
      name: 'Vertical Alignment',
      options: ['top', 'bottom'],
      type: 'select',
    },
    columnCount: {
      name: 'Column Count',
      options: ['two', 'three', 'four'],
      type: 'select',
    },
    image: {
      name: 'With image',
      type: 'boolean',
    },
    withAnimation: {
      name: 'With Animation',
      type: 'boolean',
    },
  },
  args: {
    globalTheme: 'one',
    presentationStyle: 'number',
    alignment: 'left',
    verticalAlignment: 'top',
    gridCount: 'three',
    image: false,
    withAnimation: false,
  },
};

export const Tiles = ({
  presentationStyle,
  alignment,
  verticalAlignment,
  columnCount,
  image,
  withAnimation,
}) => {
  return `
    <div class="wrap-for-global-theme">
      ${tilesTwig({
        tiles__alignment: alignment,
        tiles__vertical_alignment: verticalAlignment,
        tiles__presentation_style: presentationStyle,
        tiles__grid_count: columnCount,
        tiles__with__image: image ? 'true' : 'false',
        tiles__with__animation: withAnimation ? 'true' : 'false',
        ...tilesData,
        ...imageData.responsive_images['1x1'],
      })}
    </div>
    `;
};
