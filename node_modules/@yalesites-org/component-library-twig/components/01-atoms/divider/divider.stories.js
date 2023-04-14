import tokens from '@yalesites-org/tokens/build/json/tokens.json';

import dividerTwig from './yds-divider.twig';

const layoutOptions = Object.keys(tokens.layout['flex-position']);
const thicknessOptions = Object.keys(tokens.border.thickness);
const widths = Object.keys(tokens.layout.width);

export default {
  title: 'Atoms/Divider',
  argTypes: {
    thickness: {
      name: 'Line thickness',
      options: thicknessOptions,
      type: 'select',
      defaultValue: 'hairline',
    },
    dividerColor: {
      name: 'Line Color',
      options: ['gray-500', 'blue-yale', 'accent'],
      type: 'select',
      defaultValue: 'gray-500',
    },
    width: {
      name: 'Divider width',
      options: [...widths, 'View-All'],
      type: 'select',
      defaultValue: 'View-All',
    },
    position: {
      name: 'Divider position',
      options: layoutOptions,
      type: 'select',
      defaultValue: 'center',
    },
  },
};

export const Dividers = ({ position, thickness, dividerColor, width }) => {
  const customProperties = {
    '--thickness-theme-divider': `var(--size-thickness-${thickness})`,
  };

  const root = document.documentElement;
  Object.entries(customProperties).forEach((entry) => {
    const [key, value] = entry;
    root.style.setProperty(key, value);
  });

  return `
  <div style="--thickness-divider: var(--size-thickness-hairline)">${dividerTwig()}</div>
  <div style="--thickness-divider: var(--size-thickness-1)">${dividerTwig()}</div>
  <div style="--thickness-divider: var(--size-thickness-2)">${dividerTwig()}</div>
  <div style="--thickness-divider: var(--size-thickness-4)">${dividerTwig()}</div>
  <div style="--thickness-divider: var(--size-thickness-6)">${dividerTwig()}</div>
  <div style="--thickness-divider: var(--size-thickness-8)">${dividerTwig()}</div>
  <div style="
    --color-divider: var(--color-${dividerColor});
    --position-divider: var(--layout-flex-position-${position});
    --width-theme-divider: var(--layout-width-${width});
  ">
    <h2>Playground</h2>
    <p>Use the StoryBook controls to see the dividers below implement the available positions, thicknesses, and colors.</p>
    <div style="--width-divider: var(--layout-width-${width}, var(--layout-width-25))">
    ${dividerTwig()}
    </div>
    <div style="--width-divider: var(--layout-width-${width}, var(--layout-width-50))">
    ${dividerTwig()}
    </div>
    <div style="--width-divider: var(--layout-width-${width}, var(--layout-width-75))">
    ${dividerTwig()}
    </div>
    <div style="--width-divider: var(--layout-width-${width}, var(--layout-width-100))">
    ${dividerTwig()}
    </div>
  </div>
  `;
};
