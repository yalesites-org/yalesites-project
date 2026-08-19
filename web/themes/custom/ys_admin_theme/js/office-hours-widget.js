/**
 * @file
 * Editor affordances for the contrib Office Hours widget.
 *
 * The contrib widget stores exactly two states per weekday: a day with time
 * slots (open) or a day with none (closed). "All day" is stored as 00:00-00:00,
 * and there is no third column to record "closed" separately from "not filled
 * in yet". Rather than leave "empty" carrying that meaning silently, this adds
 * an explicit Closed checkbox per day that mirrors the stored state, and
 * collapses the three per-row operation links into one overflow menu.
 */

((Drupal) => {
  const TIME_FIELDS = "input.form-time, select.form-select";

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
   * The control is not submitted: a closed day is stored as a day with no
   * hours, which is what the checkbox reflects. Ticking it clears the day's
   * times; entering a time unticks it again.
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
    const allDayCell = allDay.closest("td");
    const allDayIndex = allDayCell.cellIndex;

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

    const sync = () => {
      const hasHours = timeFields(dayRows).some((field) => field.value !== "");
      closed.checked = !allDay.checked && !hasHours;
      // A day cannot be both open around the clock and closed.
      closed.disabled = allDay.checked;
    };

    closed.addEventListener("change", () => {
      const fields = timeFields(dayRows);
      if (closed.checked) {
        fields.forEach((field) => {
          const input = field;
          input.value = "";
        });
        return;
      }
      // Unticking means "I am about to set hours". Deliberately do NOT re-sync
      // here, or the box would immediately tick itself again; move the cursor
      // to the day's first From field instead. Entering a time settles it.
      if (fields.length) {
        fields[0].focus();
      }
    });

    // Contrib disables the time fields itself on All day, but leaves the
    // Closed state stale on the way back out, so recompute both directions.
    allDay.addEventListener("change", sync);

    timeFields(dayRows).forEach((field) => {
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
