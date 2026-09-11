/**
 * @file
 * Editor affordances for the contrib Office Hours widget.
 *
 * Adds an explicit Closed control per weekday and collapses the three per-row
 * operation links into one overflow menu.
 *
 * The contrib field stores four columns per slot - `day`, `starthours`,
 * `endhours` and `comment` (`OfficeHoursItemBase::schema()`). "All day" is not
 * a column; it is encoded as `starthours = endhours = 0`. The `comment` column
 * is what makes "closed" representable separately from "not filled in yet":
 * `OfficeHoursItem::isValueEmpty()` treats a weekday with no hours but a
 * non-empty comment as NOT empty, so that day persists, while a day the editor
 * never touched is never stored. The formatter's `keepOpenDays()` then filters
 * on whether a row exists rather than on whether it has hours, so under
 * `show_closed: open` a deliberately-closed day renders and an untouched day
 * does not.
 *
 * The Closed control therefore writes the day's comment instead of submitting a
 * value of its own: ticking it clears that day's times and labels the day
 * Closed, which is exactly the state the front end will render. With the
 * comment column turned off in the field settings there is nowhere to record
 * the state, so no control is added rather than one that cannot store anything.
 */

((Drupal) => {
  const TIME_FIELDS = "input.form-time, select.form-select";
  const COMMENT_FIELD = 'input[data-drupal-selector$="-comment"]';

  /**
   * Collects the time inputs belonging to one weekday, across its slot rows.
   *
   * @param {Array<HTMLTableRowElement>} dayRows
   *   Every slot row for a single weekday.
   *
   * @return {Array<HTMLElement>}
   *   The day's start/end time inputs.
   */
  const timeFields = (dayRows) =>
    dayRows.reduce(
      (fields, row) =>
        fields.concat(Array.from(row.querySelectorAll(TIME_FIELDS))),
      []
    );

  /**
   * Collects the comment inputs belonging to one weekday, across its slot rows.
   *
   * @param {Array<HTMLTableRowElement>} dayRows
   *   Every slot row for a single weekday.
   *
   * @return {Array<HTMLElement>}
   *   The day's comment inputs.
   */
  const commentFields = (dayRows) =>
    dayRows.reduce(
      (fields, row) =>
        fields.concat(Array.from(row.querySelectorAll(COMMENT_FIELD))),
      []
    );

  /**
   * Tells a day's first slot row from its continuation rows.
   *
   * Contrib marks the first slot of each day with a `th` first cell and its
   * continuation slots with a `td`.
   *
   * @param {HTMLTableRowElement} row
   *   A slot row.
   *
   * @return {boolean}
   *   TRUE for the first slot of a day.
   */
  const isFirstSlotRow = (row) =>
    !!row.cells[0] && row.cells[0].tagName === "TH";

  /**
   * Reads the day label that a slot row belongs under.
   *
   * Only a day's first slot row carries the real label - continuation rows
   * carry contrib's "and" connector - so walk back to the first slot row.
   *
   * @param {HTMLTableRowElement} row
   *   A slot row.
   *
   * @return {string}
   *   The day name, or an empty string on an as-yet-undated exception row.
   */
  const dayLabelOf = (row) => {
    let candidate = row;
    while (candidate && !isFirstSlotRow(candidate)) {
      candidate = candidate.previousElementSibling;
    }
    return candidate ? candidate.cells[0].textContent.trim() : "";
  };

  /**
   * Adds a Closed control to one weekday.
   *
   * The control has no form value of its own. It writes the day's `comment`
   * column, which is what makes the closed day persist and render - see the
   * file docblock.
   *
   * @param {Array<HTMLTableRowElement>} dayRows
   *   Every slot row for a single weekday, first slot first.
   *
   * @return {number}
   *   The cell index the Closed column occupies, or -1 if none was added.
   */
  const addClosedControl = (dayRows) => {
    const firstRow = dayRows[0];
    const allDay = firstRow.querySelector(
      'input[type="checkbox"][data-drupal-selector$="-all-day"]'
    );
    // The All day column is optional in the field settings. Without it there
    // is nothing to sit beside and nothing to be mutually exclusive with.
    if (!allDay) {
      return -1;
    }
    // The comment column is optional too, and it is the only place a closed
    // day can be recorded. Without it, offer no control rather than one that
    // silently stores nothing.
    const comment = firstRow.querySelector(COMMENT_FIELD);
    if (!comment) {
      return -1;
    }
    // Writes go to the first slot, where contrib shows a day-level note, but
    // reads span every slot: a note the editor typed into a continuation row
    // stores that day too, and the tick has to reflect that or it would
    // misreport what the front end renders.
    const dayComments = commentFields(dayRows);
    const allDayCell = allDay.closest("td");
    const allDayIndex = allDayCell.cellIndex;
    const closedLabel = Drupal.t("Closed");

    const closed = document.createElement("input");
    closed.type = "checkbox";
    closed.id = `${allDay.id}-ys-closed`;
    // Deliberately not "form-checkbox": contrib's Clear and Copy handlers act
    // on every .form-checkbox in a row and expect a named all_day input.
    closed.className =
      "form-boolean form-boolean--type-checkbox office-hours-closed__input";

    const label = document.createElement("label");
    label.className = "form-item__label visually-hidden";
    label.setAttribute("for", closed.id);
    // Name it per day: seven checkboxes all called "Closed" are useless to a
    // screen reader user tabbing the widget.
    label.textContent = Drupal.t("Closed on @day", {
      "@day": dayLabelOf(firstRow),
    });

    const cell = document.createElement("td");
    cell.className = "office-hours-closed";
    cell.append(label, closed);
    // Sits directly after the All day cell.
    allDayCell.after(cell);

    // Keep the remaining slot rows of this day aligned with the new column.
    // Their all_day input is a hidden field, so match on cell position.
    dayRows.slice(1).forEach((row) => {
      const spacer = document.createElement("td");
      spacer.className = "office-hours-closed";
      row.children[allDayIndex].after(spacer);
    });

    const dayTimeFields = timeFields(dayRows);
    const isBlank = (field) => field.value.trim() === "";
    const hasHours = () => dayTimeFields.some((field) => field.value !== "");

    // Retracts the label we wrote, and only that: an editor's own note is
    // theirs to keep. Anything that makes the day not-closed calls this, or
    // the label would outlive the state it describes and render publicly
    // alongside the hours.
    const retractLabel = () => {
      if (comment.value.trim().toLowerCase() === closedLabel.toLowerCase()) {
        comment.value = "";
      }
    };

    // A note the editor wrote themselves, on any slot of this day.
    const hasOwnNote = () =>
      dayComments.some(
        (field) =>
          !isBlank(field) &&
          field.value.trim().toLowerCase() !== closedLabel.toLowerCase()
      );

    const sync = () => {
      const open = hasHours();
      // A day with no hours persists only because some slot's comment is
      // filled in, so that is exactly the stored state the tick reflects.
      closed.checked = !allDay.checked && !open && !dayComments.every(isBlank);
      // Closed cannot coexist with open around the clock. And a day closed by
      // the editor's own note really is closed, but retracting it would mean
      // deleting that note - so report the state and leave them to clear the
      // note or enter hours, rather than let the tick fight them.
      closed.disabled = allDay.checked || (!open && hasOwnNote());
    };

    closed.addEventListener("change", () => {
      if (closed.checked) {
        dayTimeFields.forEach((field) => {
          const input = field;
          input.value = "";
        });
        // Label the day only when no slot already carries a note: an editor
        // who wrote "Closed for renovation" said it better than we would, and
        // adding ours alongside would render both.
        if (dayComments.every(isBlank)) {
          comment.value = closedLabel;
        }
        return;
      }
      // Unticking means "I am about to set hours", so drop our label and move
      // the cursor to the day's first From field. No re-sync needed: the box
      // is only ever enabled here when our label is the day's sole comment,
      // so retracting it already leaves the state correct.
      retractLabel();
      if (dayTimeFields.length) {
        dayTimeFields[0].focus();
      }
    });

    allDay.addEventListener("change", () => {
      // Open around the clock contradicts our label, so retract it.
      if (allDay.checked) {
        retractLabel();
      }
      // Contrib disables the time fields itself on All day, but leaves the
      // Closed state stale on the way back out, so recompute both directions.
      sync();
    });

    dayTimeFields.forEach((field) => {
      field.addEventListener("change", () => {
        // Entering hours contradicts our label as surely as All day does.
        if (hasHours()) {
          retractLabel();
        }
        sync();
      });
    });

    // Typing a note on a day with no hours is itself a closed day.
    dayComments.forEach((field) => {
      field.addEventListener("change", sync);
    });

    // Contrib's Clear and Copy links rewrite the inputs without firing events.
    dayRows.forEach((row) => {
      row
        .querySelectorAll(
          '[data-drupal-selector$="clear"], [data-drupal-selector$="copy"]'
        )
        .forEach((link) => {
          link.addEventListener("click", () => {
            window.setTimeout(sync, 0);
          });
        });
    });

    sync();
    return allDayIndex + 1;
  };

  /**
   * Adds the Closed column to a weekday table.
   *
   * @param {HTMLTableElement} table
   *   The weekday table.
   */
  const addClosedColumn = (table) => {
    const byDay = new Map();
    table.querySelectorAll("tbody tr.office-hours-slot").forEach((row) => {
      const day = row.getAttribute("office_hours_day");
      if (day === null) {
        return;
      }
      if (!byDay.has(day)) {
        byDay.set(day, []);
      }
      byDay.get(day).push(row);
    });

    // Add the body cells first: the header only earns its place once at least
    // one day actually got a control, otherwise the columns would shift.
    let columnIndex = -1;
    byDay.forEach((dayRows) => {
      columnIndex = Math.max(columnIndex, addClosedControl(dayRows));
    });
    if (columnIndex < 0) {
      return;
    }

    const headerRow = table.querySelector("thead tr");
    if (headerRow && headerRow.cells[columnIndex - 1]) {
      const header = document.createElement("th");
      header.className = "th__closed";
      header.textContent = Drupal.t("Closed");
      headerRow.cells[columnIndex - 1].after(header);
    }
  };

  /**
   * Collapses a row's operation links into a single overflow menu.
   *
   * @param {HTMLTableRowElement} row
   *   A slot row of either the weekday or the exceptions table.
   */
  const collapseOperations = (row) => {
    const cell = row.querySelector("td:last-child");
    const links = cell
      ? Array.from(cell.querySelectorAll("a.js-office-hours-operation"))
      : [];
    if (!links.length) {
      return;
    }

    // Name every toggle distinctly - a form can carry a dozen of them.
    const dayLabel = dayLabelOf(row);

    const name = document.createElement("span");
    name.className = "visually-hidden";
    if (!dayLabel) {
      name.textContent = Drupal.t("Actions for this exception");
    } else if (isFirstSlotRow(row)) {
      name.textContent = Drupal.t("Actions for @day", { "@day": dayLabel });
    } else {
      name.textContent = Drupal.t("Actions for @day, extra time slot", {
        "@day": dayLabel,
      });
    }

    const toggle = document.createElement("summary");
    toggle.className = "ys-office-hours-actions__toggle";
    toggle.append(name);

    const menu = document.createElement("div");
    menu.className = "ys-office-hours-actions__menu";
    menu.append(...links);

    const details = document.createElement("details");
    details.className = "ys-office-hours-actions";
    details.append(toggle, menu);

    // Contrib's handlers preventDefault, so close the menu ourselves.
    menu.addEventListener("click", () => {
      details.open = false;
    });

    cell.append(details);
  };

  /**
   * Tells the weekday table apart from the exceptions table.
   *
   * Both render the same columns, so this keys off the day cell: the weekday
   * widget renders it as a hidden input, the exceptions widget as a date
   * field. Header text is not usable here - Gin derives its `th__*` classes
   * from the translated header label, so they change with the interface
   * language.
   *
   * @param {HTMLTableElement} table
   *   A table inside an office hours widget.
   *
   * @return {boolean}
   *   TRUE for the Sun-Sat table.
   */
  const isWeekdayTable = (table) =>
    !table.querySelector('tbody input[type="date"]') &&
    !!table.querySelector(
      'tbody input[type="checkbox"][data-drupal-selector$="-all-day"]'
    );

  Drupal.behaviors.ysAdminThemeOfficeHours = {
    attach(context) {
      once("ys-office-hours-closed", ".field--type-office-hours table", context)
        .filter(isWeekdayTable)
        .forEach(addClosedColumn);

      once(
        "ys-office-hours-actions",
        ".field--type-office-hours tr.office-hours-slot",
        context
      ).forEach(collapseOperations);
    },
  };
})(Drupal);
