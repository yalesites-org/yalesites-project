import componentWrapperTwig from './yds-component-wrapper.twig';

/**
 * Storybook Definition.
 */
export default {
  title: 'Organisms/Component Wrapper',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    componentWidth: {
      name: 'Component Width',
      type: 'select',
      options: ['content', 'highlight', 'site', 'max'],
      defaultValue: 'content',
    },
  },
};

export const ComponentWrapper = ({ componentWidth }) => {
  return componentWrapperTwig({
    component_width: componentWidth,
  });
};
