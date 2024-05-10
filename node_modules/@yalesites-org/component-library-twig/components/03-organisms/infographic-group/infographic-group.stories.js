import tokens from '@yalesites-org/tokens/build/json/tokens.json';
// get global themes as `label` : `key` values to pass into options as array.
import getGlobalThemes from '../../00-tokens/colors/color-global-themes';

// infographic__group twig
import infographicGroupTwig from './yds-infographic-group.twig';

// Stat default data
import infographicGroupData from './infographic-group.yml';

// Image atom component - generic images for demo
import imageData from '../../01-atoms/images/image/image.yml';

// Get global theme options
const siteGlobalThemeOptions = getGlobalThemes(tokens['global-themes']);

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Infographic Group',
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
    themeColor: {
      name: 'Component Theme (dial)',
      options: ['one', 'two', 'three'],
      type: 'select',
      defaultValue: 'one',
    },
    infographicGroupHeading: {
      name: 'Infographic Group Heading',
      type: 'string',
      defaultValue: infographicGroupData.infographic__group__heading,
    },
    infographicGroupContent: {
      name: 'Infographic Group Content',
      type: 'string',
      defaultValue: infographicGroupData.infographic__group__content,
    },
    infographicGroupLink: {
      name: 'Infographic Group Link',
      type: 'string',
      defaultValue: infographicGroupData.infographic__group__link__content,
    },
    image: {
      name: 'With image',
      type: 'boolean',
      defaultValue: true,
    },
    infographicGroupIcons: {
      name: 'Infographic Group Icons',
      type: 'boolean',
      defaultValue: false,
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
  },
};

export const InfographicGroup = ({
  infographicGroupHeading,
  infographicGroupContent,
  infographicGroupIcons,
  globalTheme,
  presentationStyle,
  fontStyle,
  alignment,
  themeColor,
  image,
}) => {
  return `
    <div class="wrap-for-global-theme" data-global-theme="${globalTheme}">
      ${infographicGroupTwig({
        site_global__theme: globalTheme,
        infographic__group__heading: infographicGroupHeading,
        infographic__group__content: infographicGroupContent,
        infographic__group__has_icon: infographicGroupIcons ? 'true' : 'false',
        infographic__group__alignment: alignment,
        infographic__group__presentation_style: presentationStyle,
        infographic__group__font_style: fontStyle,
        infographic__group__theme: themeColor,
        infographic__group__bg_image: image,
        ...infographicGroupData,
        ...imageData.responsive_images['16x9'],
      })}
    </div>
    `;
};
