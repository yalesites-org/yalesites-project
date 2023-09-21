import alertTwig from './yds-alert.twig';
import textFieldTwig from '../text/yds-text-field.twig';
import ctaTwig from '../../01-atoms/controls/cta/yds-cta.twig';

import alertData from './alert.yml';

import './yds-alert';

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Alert',
  parameters: {
    layout: 'fullscreen',
  },
  argTypes: {
    heading: {
      name: 'Alert Heading',
      type: 'string',
      defaultValue: alertData.alert__heading,
    },
    content: {
      name: 'Alert Content',
      type: 'string',
      defaultValue: alertData.alert__content,
    },
    linkContent: {
      name: 'Alert Link Text',
      type: 'string',
      defaultValue: alertData.alert__link__content,
    },
  },
};

const alertResetInstructions = `
<h2>Resetting Alerts in Storybook</h2><p>Once you've closed a dismissible alert, they will not show up again, even after page reloads. In order to see them again, here in storybook, click this reset button, and all alerts will be reset to their initial state.</p>
${ctaTwig({
  cta__content: 'Reset dismissed alerts',
  cta__attributes: { onClick: 'resetAlerts();' },
  cta__component_theme: 'one',
})}
`;

export const Alert = ({ type, heading, content, linkContent }) => `
<script>
  const resetAlerts = () => {
    Object.keys(localStorage).forEach((key) => {
      if (key.substring(0, 12) === 'ys-alert-id-') {
        localStorage.removeItem(key);
      }
    });

    location.reload();
  };
</script>
${alertTwig({
  alert__type: type,
  alert__heading: heading,
  alert__content: content,
  alert__link__content: linkContent,
  alert__link__url: alertData.alert__link__url,
  alert__id: '123',
})}<br />
${textFieldTwig({
  text_field__content: alertResetInstructions,
})}`;
Alert.argTypes = {
  type: {
    name: 'Alert Type',
    type: 'select',
    options: ['emergency', 'announcement', 'marketing'],
    defaultValue: 'announcement',
  },
};

export const AlertExamples = ({ heading, content, linkContent }) => `
<script>
  const resetAlerts = () => {
    Object.keys(localStorage).forEach((key) => {
      if (key.substring(0, 12) === 'ys-alert-id-') {
        localStorage.removeItem(key);
      }
    });

    location.reload();
  };
</script>
${alertTwig({
  alert__type: 'emergency',
  alert__heading: heading,
  alert__content: content,
  alert__link__content: linkContent,
  alert__link__url: alertData.alert__link__url,
  alert__id: '234',
})}
${alertTwig({
  alert__type: 'announcement',
  alert__heading: heading,
  alert__content: content,
  alert__link__content: linkContent,
  alert__link__url: alertData.alert__link__url,
  alert__id: '345',
})}
${alertTwig({
  alert__type: 'marketing',
  alert__heading: heading,
  alert__content: content,
  alert__link__content: linkContent,
  alert__link__url: alertData.alert__link__url,
  alert__id: '456',
})}<br />
${textFieldTwig({
  text_field__content: alertResetInstructions,
})}`;
