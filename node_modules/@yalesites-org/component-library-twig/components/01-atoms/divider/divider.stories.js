import tokens from '@yalesites-org/tokens/build/json/tokens.json';

import dividerTwig from './divider.twig';

const layoutOptions = Object.keys(tokens.layout['flex-position']);
const thicknessOptions = Object.keys(tokens.border.thickness);

export default {
  title: 'Atoms/Divider',
  argTypes: {
    position: {
      options: layoutOptions,
      type: 'select',
      defaultValue: 'center',
    },
    thickness: {
      options: thicknessOptions,
      type: 'select',
      defaultValue: 'hairline',
    },
    dividerColor: {
      options: ['gray-500', 'blue-yale', 'accent'],
      type: 'select',
      defaultValue: 'gray-500',
    },
  },
};

export const Dividers = ({ position, thickness, dividerColor }) => `
  ${dividerTwig({ divider__thickness: 'hairline' })}<br />
  ${dividerTwig({ divider__thickness: '1' })}<br />
  ${dividerTwig({ divider__thickness: '2' })}<br />
  ${dividerTwig({ divider__thickness: '4' })}<br />
  ${dividerTwig({ divider__thickness: '6' })}<br />
  ${dividerTwig({ divider__thickness: '8' })}<br />
  <div style="--color-divider: var(--color-${dividerColor})">
    <h2>Playground</h2>
    <p>Use the StoryBook controls to see the dividers below implement the available positions, thicknesses, and colors.</p>
    ${dividerTwig({
      divider__width: '25',
      divider__thickness: thickness,
      divider__position: position,
    })}<br />
    ${dividerTwig({
      divider__width: '50',
      divider__thickness: thickness,
      divider__position: position,
    })}<br />
    ${dividerTwig({
      divider__width: '75',
      divider__thickness: thickness,
      divider__position: position,
    })}<br />
    ${dividerTwig({
      divider__width: '100',
      divider__thickness: thickness,
      divider__position: position,
    })}
  </div>
`;
