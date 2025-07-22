import tokens from '@yalesites-org/tokens/build/json/tokens.json';

import dividerTwig from './yds-divider.twig';

import './cl-dividers.scss';
import '../../00-tokens/effects/yds-animate';

const layoutOptions = ['left', 'center'];
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
      options: ['gray-500', 'blue-yale', 'basic-brown-gray'],
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
    sectionTheme: {
      name: 'Section Theme',
      type: 'select',
      options: ['default', 'one', 'two', 'three', 'four'],
      control: { type: 'select' },
    },
  },
  args: {
    thickness: 'hairline',
    dividerColor: 'gray-500',
    width: 'View-All',
    position: 'center',
    sectionTheme: 'default',
  },
};

export const Dividers = ({
  position,
  thickness,
  dividerColor,
  width,
  sectionTheme,
}) => {
  const customProperties = {
    '--thickness-theme-divider': `var(--size-thickness-${thickness})`,
  };

  const root = document.documentElement;
  Object.entries(customProperties).forEach((entry) => {
    const [key, value] = entry;
    root.style.setProperty(key, value);
  });

  const viewAll = width === 'View-All';

  return `
  <div style="--thickness-divider: var(--size-thickness-hairline)">${dividerTwig()}</div>
  <div style="--thickness-divider: var(--size-thickness-1)">${dividerTwig()}</div>
  <div style="--thickness-divider: var(--size-thickness-2)">${dividerTwig()}</div>
  <div style="--thickness-divider: var(--size-thickness-4)">${dividerTwig()}</div>
  <div style="--thickness-divider: var(--size-thickness-6)">${dividerTwig()}</div>
  <div style="--thickness-divider: var(--size-thickness-8)">${dividerTwig()}</div>
  <div class="yds-layout cl-divider-playground" data-component-theme="${sectionTheme}">
    <div class="yds-layout__inner" data-component-width="site" style="
      --color-divider: var(--color-${dividerColor});
      --width-theme-divider: var(--layout-width-${width});
    ">
      <div class="yds-layout__primary">
        <h2>Playground</h2>
        <p>Use the StoryBook controls to see the dividers below implement the available positions, thicknesses, and colors.</p>

        ${dividerTwig({
          divider__width: `${viewAll ? '25' : width}`,
          divider__position: `${position}`,
        })}
        ${dividerTwig({
          divider__width: `${viewAll ? '50' : width}`,
          divider__position: `${position}`,
        })}
        ${dividerTwig({
          divider__width: `${viewAll ? '75' : width}`,
          divider__position: `${position}`,
        })}
        ${dividerTwig({
          divider__width: `${viewAll ? '100' : width}`,
          divider__position: `${position}`,
        })}
      </div>
    </div>
  </div>
  <div class="padding-to-see-dividers-above">&nbsp;</div>
  `;
};
