// Twig templates.
import mediaGridTwig from './media-grid/yds-media-grid.twig';

// Data files.
import mediaGridData from './media-grid/media-grid.yml';

// JS.
import './media-grid/yds-media-grid-interactive';

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Galleries',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    gridHeading: {
      name: 'Gallery heading',
      type: 'string',
    },
  },
  args: {
    gridHeading: mediaGridData.media_grid__heading,
  },
};

export const ImageGrid = ({ gridHeading }) => {
  return mediaGridTwig({
    ...mediaGridData,
    media_grid__heading: gridHeading,
  });
};

export const InteractiveGrid = ({ gridHeading }) => `
  ${mediaGridTwig({
    ...mediaGridData,
    media_grid__variation: 'interactive',
    media_grid__heading: gridHeading,
  })}
  ${mediaGridTwig({
    ...mediaGridData,
    media_grid__variation: 'interactive',
    media_grid__heading: gridHeading,
  })}
`;
