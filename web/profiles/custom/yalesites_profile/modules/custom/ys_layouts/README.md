# YaleSites Layouts

## Description
The layouts module organizes work related to YaleSite's implementation of Layout Builder. This includes the definition of custom layouts including the banner, page meta, and two column sections.

## Meta Fields Manager

This module includes a service called `MetaFieldsManager` that was specifically made generic to support other meta data (field data) for any content type to be used in different ways. The first use of this is for events. Event data has some fields that need to be used in multiple places (custom block and various view modes) and also have some additional calculation required before display. For example, the date field is calculated ahead of time to provide the following information to twig templates in an array:

`event_dates`

* value - Raw Unix timestamp of start date and time (i.e. 1714757400)
* end_value - Raw Unix timestamp of end date and time (i.e. 1714761000)
* duration - Duration in minutes (i.e. 60)
* timezone - The timezone (i.e. America/New_York)
* formatted_start_date - Formatted as: Friday, May 3rd, 2024
* formatted_start_time - Formatted as: 1:30 pm EDT
* formatted_end_date - Formatted as: Friday, May 3rd, 2024
* formatted_end_time - Formatted as: 2:30 pm EDT
* is_all_day - Boolean for all day events (i.e. false)

The `ics_url` is auto-calculated if there is an ICS URL provided from Localist, then use it. If not, calculate an ICS URL dynamically from the first date in the series.
