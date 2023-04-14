import pager from './yds-pager.twig';

import pagerData from './pager.yml';
import pagerFirstData from './pager-first.yml';
import pagerLastData from './pager-last.yml';
import pagerFirstAndLastData from './pager-first-and-last.yml';

// Demo JS.
import './cl-pager';

/**
 * Storybook Definition.
 */
export default { title: 'Molecules/Pager' };

export const Basic = () => pager(pagerData);

export const First = () => pager({ ...pagerData, ...pagerFirstData });

export const Last = () => pager({ ...pagerData, ...pagerLastData });

export const FirstAndLast = () =>
  pager({ ...pagerData, ...pagerFirstAndLastData });
