// Checkbox, radio and select elements are commented out because we
// may use these soon, but not yet.
// import checkbox from './checkbox/yds-checkbox.twig';
// import radio from './radio/yds-radio.twig';
import select from './select/yds-select.twig';
import textfields from './textfields/yds-textfields.twig';
import formExample from './contact-form-example.twig';

// import checkboxData from './checkbox/checkbox.yml';
// import radioData from './radio/radio.yml';
import selectOptionsData from './select/select.yml';

/**
 * Storybook Definition.
 */
export default { title: 'Atoms/Forms' };

// export const checkboxes = () => checkbox(checkboxData);

// export const radioButtons = () => radio(radioData);

export const selectDropdowns = () => select(selectOptionsData);

export const textfieldsExamples = () => textfields();

export const exampleForm = ({ buttonTheme, sectionTheme }) => `
  <div data-component-has-divider="false" data-component-theme="${sectionTheme}" data-component-width="site" class="yds-layout" data-embedded-components="" data-spotlights-position="first">
    <div class="yds-layout__inner">
      <div class="yds-layout__primary">
        <h2>Pre-Built Form</h2>
        ${formExample({ buttonTheme })}
      </div>
    </div>
  </div>
`;

exampleForm.argTypes = {
  sectionTheme: {
    name: 'Section Theme',
    type: 'select',
    options: ['default', 'one', 'two', 'three', 'four'],
    control: { type: 'select' },
  },
  buttonTheme: {
    name: 'Button Theme',
    type: 'select',
    options: ['one', 'two', 'three', 'four', 'five', 'six', 'seven'],
    control: { type: 'select' },
  },
};

exampleForm.args = {
  sectionTheme: 'default',
  buttonTheme: 'one',
};
