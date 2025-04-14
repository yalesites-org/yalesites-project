import './yds-calendar';
import calendarTwig from './yds-calendar.twig';
import monthData from './calendar.yml';

export default {
  title: 'Organisms/Calendar',
};

export const Calendar = () => {
  return calendarTwig({ month: monthData });
};
