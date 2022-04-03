import button from './button.twig';

/**
 * Storybook Definition.
 */
export default { title: 'Atoms/Button' };

export const Button = () => `
  <h2>Filled</h2>
  <div class="button-group">
    ${button({
      button_content: 'Button',
    })}
    ${button({
      button_content: 'Button',
      button_radius: 'soft',
    })}
    ${button({
      button_content: 'Button',
      button_radius: 'pill',
    })}
  </div>
  <h2>Outline</h2>
  <div class="button-group">
    ${button({
      button_content: 'Button',
      button_style: 'outline',
    })}
    ${button({
      button_content: 'Button',
      button_radius: 'soft',
      button_style: 'outline',
    })}
    ${button({
      button_content: 'Button',
      button_radius: 'pill',
      button_style: 'outline',
    })}
  </div>
  <h2>Outline Weights</h2>
  <div class="button-group">
    ${button({
      button_content: 'Button',
      button_style: 'outline',
      button_outline_weight: '1',
    })}
    ${button({
      button_content: 'Button',
      button_style: 'outline',
      button_outline_weight: '2',
    })}
    ${button({
      button_content: 'Button',
      button_style: 'outline',
      button_outline_weight: '4',
    })}
  </div>
`;
