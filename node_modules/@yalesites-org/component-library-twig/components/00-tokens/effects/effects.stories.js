import tokens from '@yalesites-org/tokens/build/json/tokens.json';

import shadowsTwig from './shadows.twig';
import radiiTwig from './radii.twig';
import bordersTwig from './borders.twig';

const shadowsData = { shadows: tokens.dropShadow, prefix: '--drop-shadow-' };
const radiiData = { radii: tokens.radius, prefix: '--radius-' };
const bordersData = {
  borders: tokens.border.thickness,
  prefix: '--border-thickness-',
};

export default {
  title: 'Tokens/Effects',
};

export const Shadows = () => `
  <h2>Shadows should only be used as hover or interaction effect</h2>
  <p>The five levels are displayed below. Hover over each box to see the shadow effect.</p>
  ${shadowsTwig(shadowsData)}
`;

export const Radius = () => `
  <h2>Radius selection will affect the appearance of cards and list groups</h2>
  ${radiiTwig(radiiData)}
`;

export const Borders = () => `
  <p>Thick borders should be reserved for dividers on headers and footers and not appear on cards or other components.</p>
  ${bordersTwig(bordersData)}
`;
