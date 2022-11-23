import videoEmbedTwig from './yds-video-embed.twig';

import videoEmbedData from './video-embed.yml';

/**
 * Storybook Definition.
 */
export default { title: 'Atoms/Video Embed' };

export const videoEmbed = () => videoEmbedTwig(videoEmbedData);
