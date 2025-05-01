import MicroModal from 'micromodal';

Drupal.behaviors.eventsCalendar = {
  attach(context) {
    // Classes
    const calendar = '.calendar';
    const event = '.calendar-event';
    const eventClass = 'calendar-event';
    const visibleClass = 'is-visible';
    const day = '.calendar__day';
    const modalContent = '.modal__calendar-events';
    const modalTitleSelector = '.modal__title';
    const eventToggle = '.calendar-event__toggle';
    const prevButton = '.calendar__nav-btn--prev';
    const nextButton = '.calendar__nav-btn--next';
    const desktopNav = '.calendar__nav--desktop';
    const dialogTitle = '.calendar__dialog-title';
    const dayEvents = '.calendar__day--events';
    const noEvents = '.calendar__no-events-message';
    const storybook = '.sb-show-main';

    // Selectors.
    const calendars = context.querySelectorAll(calendar);
    const eventsToggle = context.querySelectorAll(eventToggle);

    // Determines if we're in storybook (no drupal endpoints)
    const isStorybook = !(context.querySelector(storybook) === null);

    // Create a MediaQueryList.
    const mql = window.matchMedia(`(min-width: 1200px )`);

    // Create a temporary div element to parse the HTML content.
    const tempDiv = document.createElement('div');

    // Initialize MicroModal.
    MicroModal.init();

    calendars.forEach((c) => {
      const moreEventsContainer = c.querySelector(modalContent);
      const modalTitle = c.querySelector(modalTitleSelector);
      const desktopCalendarPrevBtn = c
        .querySelector(desktopNav)
        .querySelector(prevButton);
      const desktopCalendarNextBtn = c
        .querySelector(desktopNav)
        .querySelector(nextButton);
      const calendarEvents = c.querySelectorAll(dayEvents);
      const calendarNoEvents = c.querySelector(noEvents);
      const handleNoMonthEvents = () => {
        // If there are no events in the current month, show the 'No Events' text on mobile.
        if (!calendarEvents.length) {
          calendarNoEvents.classList.add(visibleClass);
        }
      };
      // Handle the 'More events' button click.
      eventsToggle.forEach((el) => {
        el.addEventListener('click', () => {
          const thisDay = el.closest(day);
          const thisEvents = thisDay.querySelectorAll(event);

          // Set the innerHTML of the temporary div to the innerHTML content of the active date time.
          tempDiv.innerHTML = thisDay.querySelector(dialogTitle).innerHTML;
          // Clear previous content.
          moreEventsContainer.innerHTML = '';
          modalTitle.innerHTML = '';
          modalTitle.innerHTML = tempDiv.textContent || tempDiv.innerText;
          thisEvents.forEach((e) => {
            const clonedEvent = e.cloneNode(true);
            clonedEvent.classList.add(`${eventClass}--modal`);
            moreEventsContainer.appendChild(clonedEvent);
          });
        });
      });

      // Helper function to find the closest calendar-wrapper with an ID that starts with 'calendar-wrapper'.
      const getCalendarWrapper = (clickEvent) =>
        clickEvent.target.closest('[id^="calendar-wrapper"]');

      // Function to refresh the calendar data via AJAX based on the given button (prev/next) and calendarWrapper.
      const refreshCalendarData = (calendarWrapper, button) => {
        if (isStorybook) return; // If we're in storybook, we don't have access to /events-calendar
        if (!calendarWrapper) return; // If no calendarWrapper found, exit the function.

        // Use Drupal's AJAX system to refresh the calendar data.
        Drupal.ajax({
          url: '/events-calendar',
          submit: {
            calendar_id: `#${calendarWrapper.id}`, // Pass the calendar wrapper ID.
            month: button.dataset.month, // Pass the selected month from the button.
            year: button.dataset.year, // Pass the selected year from the button.
          },
          progress: { type: 'fullscreen' }, // Show a fullscreen progress indicator during the refresh.
        }).execute();
      };

      const handleDesktopCalendarNavigation = () => {
        desktopCalendarPrevBtn.addEventListener('click', (clickEvent) => {
          const calendarWrapper = getCalendarWrapper(clickEvent);
          refreshCalendarData(calendarWrapper, desktopCalendarPrevBtn);
        });

        desktopCalendarNextBtn.addEventListener('click', (clickEvent) => {
          const calendarWrapper = getCalendarWrapper(clickEvent);
          refreshCalendarData(calendarWrapper, desktopCalendarNextBtn);
        });
      };
      handleDesktopCalendarNavigation();
      if (!mql.matches) {
        handleNoMonthEvents();
      }
      mql.addEventListener('change', () => {
        if (mql.matches) {
          calendarNoEvents.classList.remove(visibleClass);
        } else {
          handleNoMonthEvents();
          MicroModal.close();
        }
      });
    });
  },
};
