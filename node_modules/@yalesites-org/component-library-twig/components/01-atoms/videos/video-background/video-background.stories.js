import videoBackgroundTwig from './yds-video-background.twig';

import videoBackgroundData from './video-background.yml';

import './yds-video-background';

/**
 * Storybook Definition.
 */
export default { title: 'Atoms/Videos/Video Background' };

export const videoBackground = () => videoBackgroundTwig(videoBackgroundData);
