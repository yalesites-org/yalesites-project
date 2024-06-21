import tokens from '@yalesites-org/tokens/build/json/tokens.json';
import { eventArgTypes } from '../../04-page-layouts/cl-page-args';

import basicMetaTwig from './basic-meta/yds-basic-meta.twig';
import eventMetaTwig from './event-meta/yds-event-meta.twig';
import dateTimeTwig from '../../01-atoms/date-time/yds-date-time.twig';
import profileMetaTwig from './profile-meta/yds-profile-meta.twig';
import imageData from '../../01-atoms/images/image/image.yml';

const colorPairingsData = Object.keys(tokens['component-themes']);

// Utility to convert dates to unix timestamps
const toUnixTimeStamp = (date) => {
  return Math.floor(Date.parse(date) / 1000);
};

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
    defaultValue: `<span>By Charlyn Paradis</span>${dateTimeTwig({
      date_time__start: '2022-01-25',
      date_time__format: 'day__full',
    })}`,
  },
};

export const Event = ({
  pageTitle,
  startDate,
  endDate,
  format,
  address,
  ctaText,
  allDay,
}) =>
  eventMetaTwig({
    event_title__heading: pageTitle,
    event_meta__date_start: toUnixTimeStamp(startDate),
    event_meta__date_end: toUnixTimeStamp(endDate),
    event_meta__format: format,
    event_meta__address: address,
    event_meta__cta_primary__content: ctaText,
    event_meta__cta_primary__href: '#',
    event_meta__cta_secondary__content: 'Add to calendar',
    event_meta__cta_secondary__href: '#',
    event_meta__all_day: allDay,
  });
Event.argTypes = {
  ...eventArgTypes,
};

export const Profile = ({
  heading,
  bgColor,
  titleLine,
  subTitle,
  department,
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
