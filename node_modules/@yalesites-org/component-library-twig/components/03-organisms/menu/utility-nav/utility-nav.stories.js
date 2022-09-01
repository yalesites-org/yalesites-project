// Markup.
import utilityNavTwig from './utility-nav.twig';

// Data.
import utilityNavData from './utility-nav.yml';

/**
 * Storybook Definition.
 */
export default { title: 'Organisms/Menu/Utility Nav' };

export const UtilityNav = () => utilityNavTwig(utilityNavData);
