import tokens from '@yalesites-org/tokens/build/json/tokens.json';
import {
  eventLocalistArgs,
  eventLocalistArgTypes,
} from '../../04-page-layouts/cl-page-args';

import eventLocalistData from './event-meta/event-localist.yml';
import basicMetaTwig from './basic-meta/yds-basic-meta.twig';
import eventLocalistMetaTwig from './event-meta/yds-event-meta-localist.twig';
import dateTimeTwig from '../../01-atoms/date-time/yds-date-time.twig';
import profileMetaTwig from './profile-meta/yds-profile-meta.twig';
import imageData from '../../01-atoms/images/image/image.yml';

import './event-meta/event-meta-localist';

const colorPairingsData = Object.keys(tokens['component-themes']);

/**
 * Storybook Definition.
 */
export default {
  title: 'Molecules/Meta',
};

export const Basic = ({ meta }) => basicMetaTwig({ basic_meta: meta });
Basic.argTypes = {
  meta: {
    name: 'Meta',
    type: 'string',
  },
};
Basic.args = {
  meta: `<span>By Charlyn Paradis</span>${dateTimeTwig({
    date_time__start: '2022-01-25',
    date_time__format: 'day__full',
  })}`,
};

export const Event = ({
  pageTitle,
  format,
  address,
  ctaText,
  allDay,
  withImage,
  withCalendar,
}) =>
  eventLocalistMetaTwig({
    ...imageData.responsive_images['3x2'],
    event_title__heading: pageTitle,
    event_dates: eventLocalistData.event_dates,
    formatted_start_date: eventLocalistData.formatted_start_date,
    formatted_end_date: eventLocalistData.formatted_end_date,
    event_meta__format: format,
    event_meta__address: address,
    event_meta__cta_primary__content: ctaText,
    event_meta__cta_primary__href: '#',
    cost_button_text: ctaText,
    event_meta__cta_secondary__content: 'Add to calendar',
    event_meta__cta_secondary__href: '#',
    event_meta__with_calendar: withCalendar == null ? true : !!withCalendar,
    event_meta__image: withImage ? 'true' : 'false',
    event_meta__all_day: allDay,
    ...eventLocalistData,
  });
Event.argTypes = {
  withCalendar: {
    name: 'With Add to Calendar button',
    type: 'boolean',
    defaultValue: true,
  },
  ...eventLocalistArgTypes,
};
Event.args = eventLocalistArgs;

export const Profile = ({
  heading,
  bgColor,
  titleLine,
  subTitle,
  department,
  pronouns,
  profileImageOrientation,
  profileImageAlignment,
  profileImageStyle,
}) =>
  profileMetaTwig({
    ...imageData.responsive_images['3x2'],
    profile_meta__heading: heading,
    profile_meta__title_line: titleLine,
    profile_meta__subtitle_line: subTitle,
    profile_meta__department: department,
    profile_meta__pronouns: pronouns,
    profile_meta__background: bgColor,
    profile_meta__image_orientation: profileImageOrientation,
    image__srcset__1: imageData.responsive_images['2x3'].image__srcset,
    image__sizes__1: imageData.responsive_images['2x3'].image__sizes,
    image__alt__1: imageData.responsive_images['2x3'].image__alt,
    image__src__1: imageData.responsive_images['2x3'].image__src,
    profile_meta__image_style: profileImageStyle,
    profile_meta__image_alignment: profileImageAlignment,
  });
Profile.argTypes = {
  heading: {
    name: 'Heading',
    type: 'string',
    defaultValue: 'Person Namerton',
  },
  titleLine: {
    name: 'Profile professional title',
    type: 'string',
    defaultValue: 'Professional Title',
  },
  subTitle: {
    name: 'Profile subtitle',
    type: 'string',
    defaultValue: 'Subtitle',
  },
  department: {
    name: 'Profile department',
    type: 'string',
    defaultValue: 'Department name',
  },
  pronouns: {
    name: 'Profile pronouns',
    type: 'string',
    defaultValue: 'They/They/Them',
  },
  bgColor: {
    name: 'Component Theme (dial)',
    type: 'select',
    options: colorPairingsData,
    defaultValue: 'one',
  },
  profileImageOrientation: {
    name: 'Profile Image Orientation',
    type: 'select',
    options: ['landscape', 'portrait'],
    defaultValue: 'landscape',
  },
  profileImageAlignment: {
    name: 'Profile Image Alignment',
    type: 'select',
    options: ['left', 'right'],
    defaultValue: 'right',
  },
  profileImageStyle: {
    name: 'Profile Image Style',
    type: 'select',
    options: ['inline', 'outdent'],
    defaultValue: 'inline',
  },
};
Profile.args = {
  heading: 'Person Namerton',
  titleLine: 'Professional Title',
  subTitle: 'Subtitle',
  department: 'Department name',
  pronouns: 'They/They/Them',
  bgColor: 'one',
  profileImageOrientation: 'landscape',
  profileImageAlignment: 'right',
  profileImageStyle: 'inline',
};
