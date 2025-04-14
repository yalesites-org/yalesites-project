// Markup.
import utilityNavTwig from './yds-utility-nav.twig';

// Data.
import utilityNavData from './utility-nav.yml';

import './utility-nav-dropdown-menu';
/**
 * Storybook Definition.
 */
export default { title: 'Organisms/Menu/Utility Nav' };

export const UtilityNav = () => utilityNavTwig(utilityNavData);
