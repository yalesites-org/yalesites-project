import tokens from '@yalesites-org/tokens/build/json/tokens.json';
import siteSectionTwig from './yds-site-in-this-section.twig';

import secondaryNavData from '../menu/secondary-nav/secondary-nav.yml';

import '../menu/secondary-nav/yds-secondary-nav';
import '../../02-molecules/menu/menu-in-this-section-toggle/yds-menu-in-this-section-toggle';
import './yds-site-in-this-section';

const colorPairingsData = Object.keys(tokens['component-themes']);

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Site/In This Section',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    siteSectionTheme: {
      name: 'Component Theme (dial)',
      options: colorPairingsData,
      type: 'select',
    },
  },
  args: {
    siteSectionTheme: 'one',
  },
};

export const SiteSection = ({ siteSectionTheme }) =>
  siteSectionTwig({
    site_section_wrap__theme: siteSectionTheme,
    secondary_nav__items: secondaryNavData.items,
  });
