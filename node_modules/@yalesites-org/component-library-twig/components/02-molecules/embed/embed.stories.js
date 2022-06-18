import embedTwig from './embed.twig';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Embed',
};

export const Embed = () =>
  embedTwig({
    embed__src:
      'https://yalesurvey.ca1.qualtrics.com/jfe/form/SV_cDezt2JVsNok77o',
    embed__title: 'Example Qualtrics Form',
  });
