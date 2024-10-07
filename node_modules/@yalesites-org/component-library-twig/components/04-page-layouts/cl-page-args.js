const argTypes = {
  siteName: {
    name: 'Site Name',
    type: 'string',
    defaultValue: 'Department of Chemistry',
  },
  utilityNavLinkContent: {
    name: 'Header: Utility nav link text',
    type: 'string',
    defaultValue: null,
  },
  utilityNavSearch: {
    name: 'Header: Search',
    type: 'boolean',
    defaultValue: false,
  },
  pageTitle: {
    name: 'Page Title',
    type: 'string',
    defaultValue: 'Davis Team Project Wins Award for Research',
  },
  meta: {
    name: 'Meta',
    type: 'string',
    defaultValue: `<span>By Charlyn Paradis</span><time class="date-time" datetime="2022-01-25">January 25, 2022</time>`,
  },
};

export const eventArgTypes = {
  startDate: {
    name: 'Start Date/Time',
    type: 'string',
    defaultValue: '2022-04-01T08:00',
  },
  endDate: {
    name: 'End Date/Time',
    type: 'string',
    defaultValue: '2022-04-01T11:30',
  },
  format: {
    name: 'Format',
    control: 'select',
    options: ['In-person', 'Online', 'Hybrid'],
    defaultValue: 'In-person',
  },
  address: {
    name: 'Address',
    type: 'string',
    defaultValue:
      'Address 1 (Building name)<br />Address 2<br />City, ST ZIP | Map',
  },
  ctaText: {
    name: 'CTA Text',
    type: 'string',
    defaultValue: 'CTA for event',
  },
  allDay: {
    name: 'All Day',
    type: 'boolean',
    defaultValue: true,
  },
  pageTitle: {
    name: 'Page Title',
    type: 'string',
    defaultValue: 'Davis Team Project Wins Award for Research',
  },
};

export const eventLocalistArgTypes = {
  withImage: {
    name: 'With Image',
    type: 'boolean',
    defaultValue: true,
  },
  format: {
    name: 'Format',
    control: 'select',
    options: ['In-person', 'Online', 'Hybrid'],
    defaultValue: 'In-person',
  },
  address: {
    name: 'Address',
    type: 'string',
    defaultValue:
      'Address 1 (Building name)<br />Address 2<br />City, ST ZIP | Map',
  },
  ctaText: {
    name: 'CTA Text',
    type: 'string',
    defaultValue: 'CTA for event',
  },
  allDay: {
    name: 'All Day',
    type: 'boolean',
    defaultValue: true,
  },
  pageTitle: {
    name: 'Page Title',
    type: 'string',
    defaultValue: 'Davis Team Project Wins Award for Research',
  },
};

export default argTypes;
