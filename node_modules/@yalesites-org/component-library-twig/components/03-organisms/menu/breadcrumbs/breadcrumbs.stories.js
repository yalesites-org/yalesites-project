// Markup.
import breadcrumbsTwig from './breadcrumbs.twig';

// Data.
import breadcrumbsData from './breadcrumbs.yml';

// JavaScript.
import './breadcrumbs';

/**
 * Storybook Definition.
 */
export default { title: 'Organisms/Menu/Breadcrumbs' };

export const Breadcrumbs = () => breadcrumbsTwig(breadcrumbsData);
