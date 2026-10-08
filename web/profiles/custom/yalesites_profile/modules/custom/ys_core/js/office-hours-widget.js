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

((Drupal, once) => {
  const TIME_FIELDS = "input.form-time, select.form-select";
  const COMMENT_FIELD = 'input[data-drupal-selector$="-comment"]';
  const ALL_DAY_FIELD =
    'input[type="checkbox"][data-drupal-selector$="-all-day"]';
  // Marks a comment whose value WE wrote, so retraction never deletes an
  // editor's own prose. Read via dataset, removed via removeAttribute. It does
  // not survive a save or an AJAX rebuild; reclaimLabel() restores it.
  const MARKER_ATTR = "data-ys-closed-marker";

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
    const allDay = firstRow.querySelector(ALL_DAY_FIELD);
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

    // Retracts the label we wrote, and ONLY that.
    //
    // Provenance is tracked out of band, on a data attribute set at the moment
    // we write the label, rather than inferred by comparing the stored string
    // to the current translation of "Closed". Comparing the string cannot tell
    // our marker from an editor who simply typed "Closed" -- and this function
    // deletes what it matches, so that guess destroyed their note. It also
    // silently stopped matching whenever the interface language or the
    // translation changed, orphaning a marker we had written ourselves.
    //
    // The rule is: never delete a string we did not write, as far as this DOM
    // knows. The attribute does not survive a save or contrib's "Add time
    // slot" AJAX rebuild, so it is re-established on load by the narrow
    // reclaim at the end of this function -- exact label match on an hourless
    // day. That keeps retraction limited to the one string this widget writes,
    // while letting a saved Closed day reopen exactly like a freshly ticked
    // one.
    const retractLabel = () => {
      if (comment.dataset.ysClosedMarker === "1") {
        comment.value = "";
        comment.removeAttribute(MARKER_ATTR);
      }
    };

    // Reclaims the label across anything that drops the attribute but keeps
    // the value: a save, contrib's "Add time slot" AJAX rebuild, and contrib's
    // Copy, which carries the previous day's comment over (office_hours.js,
    // copyPreviousDay, "Copy the comment").
    //
    // Without this a day the widget closed comes back looking like an editor's
    // own note: the box checked but disabled, the time fields editable, and
    // the word "Closed" left behind when hours are typed.
    //
    // Deliberately narrow. It needs an EXACT match against the current
    // translation of the label, a day with no hours, and no other slot of the
    // day carrying its own note -- which together are the only shape this
    // widget ever writes. An editor who typed "Closed" themselves on an
    // hourless day is claimed too, and that is the accepted cost: the outcome
    // is the one they asked for, and unticking returns the field to them.
    // Anything else they wrote is still never touched. If the interface
    // language changes under a stored label it simply stops matching, and the
    // day falls back to being treated as their note rather than being eaten.
    const reclaimLabel = () => {
      const othersBlank = dayComments.every(
        (field) => field === comment || isBlank(field)
      );
      if (!hasHours() && othersBlank && comment.value.trim() === closedLabel) {
        comment.dataset.ysClosedMarker = "1";
      } else {
        // Copy rewrites the comment without an input event, so a marker left
        // over from before it would otherwise claim the copied note.
        comment.removeAttribute(MARKER_ATTR);
      }
    };

    // A note the editor wrote themselves, on any slot of this day -- anything
    // non-blank that is not a marker we wrote this session.
    const hasOwnNote = () =>
      dayComments.some(
        (field) => !isBlank(field) && field.dataset.ysClosedMarker !== "1"
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
      // Mirror contrib's All day treatment: a closed day's From/To are
      // disabled. Contrib owns the All day case, so leave that to it. A day
      // closed only by the editor's own note keeps editable fields: its box
      // is disabled, so disabling the fields too would leave no way to enter
      // hours short of clearing the note.
      if (!allDay.checked) {
        dayTimeFields.forEach((field) => {
          const input = field;
          input.disabled = closed.checked && !closed.disabled;
        });
      }
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
        //
        // The value written is the translated word, because it is what the
        // front end renders: contrib applies its own `closed_format` label
        // only when the comment is EMPTY
        // (OfficeHoursItemListFormatter, the `empty($info['comments'])` case),
        // and the comment cannot be empty or the day is not stored at all
        // (OfficeHoursItem::isValueEmpty()). The data attribute marks it as
        // ours for this DOM; across a save or an AJAX rebuild the attribute is
        // gone and reclaimLabel() re-establishes it from the value.
        if (dayComments.every(isBlank)) {
          comment.value = closedLabel;
          comment.dataset.ysClosedMarker = "1";
        }
        sync();
        return;
      }
      // Unticking means "I am about to set hours", so drop our label and move
      // the cursor to the day's first From field. No re-sync needed: the box
      // is only ever enabled here when our label is the day's sole comment,
      // so retracting it already leaves the state correct.
      retractLabel();
      // Re-enable before focusing: a disabled field drops focus. Unticking is
      // also the only way back to entering hours, since a disabled field can
      // no longer fire the change that auto-unticks Closed.
      dayTimeFields.forEach((field) => {
        const input = field;
        input.disabled = false;
      });
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
      // The moment the editor edits a comment it is theirs, even if what they
      // typed happens to match our label -- so drop our claim on it. Bound on
      // `input` rather than `change` so the claim is released as they type,
      // before anything else can read the stale provenance.
      field.addEventListener("input", () => {
        field.removeAttribute(MARKER_ATTR);
      });
      field.addEventListener("change", sync);
    });

    // Copy carries the previous day's comment over, so the label has to be
    // reclaimed before the state is recomputed, not after.
    const reclaimAndSync = () => {
      reclaimLabel();
      sync();
    };

    // Contrib's Clear and Copy find the comment by `.form-text` and the All
    // day box by `.form-checkbox`, two classes Gin Layout Builder renames
    // (`glb-form-text`, `glb-form-checkbox`). In the Layout Builder modal and
    // tray they only reach the times, so Copy leaves this day's old note and
    // All day state beside the copied hours, and Clear leaves both behind.
    // Redo those two parts by the fields' own selectors, mirroring contrib
    // (office_hours.js, clearTimeSlot and copyPreviousDay). Outside Layout
    // Builder contrib already did it, so this writes the same values again.
    const firstRowTimes = timeFields([firstRow]);
    const setAllDay = (checked) => {
      allDay.checked = checked;
      // What contrib's setAllDayTimeSlot does for this slot.
      firstRowTimes.forEach((field) => {
        const input = field;
        input.disabled = checked;
      });
    };

    const clearSlot = (row) => {
      row.querySelectorAll(COMMENT_FIELD).forEach((field) => {
        const input = field;
        input.value = "";
      });
      if (row === firstRow) {
        setAllDay(false);
      }
      reclaimAndSync();
    };

    // Sunday wraps to Saturday and slots pair up by position, as in contrib.
    const copyPreviousDay = () => {
      const day = Number(firstRow.getAttribute("office_hours_day"));
      const previousRows = firstRow
        .closest("tbody")
        .querySelectorAll(
          `tr.office-hours-slot[office_hours_day="${day === 0 ? 6 : day - 1}"]`
        );
      previousRows.forEach((source, i) => {
        const note = source.querySelector(COMMENT_FIELD);
        if (note && dayComments[i]) {
          dayComments[i].value = note.value;
        }
      });
      const previousAllDay = previousRows[0]
        ? previousRows[0].querySelector(ALL_DAY_FIELD)
        : null;
      if (previousAllDay) {
        setAllDay(previousAllDay.checked);
      }
      reclaimAndSync();
    };

    // Contrib's handlers run first; ours follow once they have finished.
    dayRows.forEach((row) => {
      row
        .querySelectorAll('[data-drupal-selector$="clear"]')
        .forEach((link) => {
          link.addEventListener("click", () => {
            window.setTimeout(clearSlot, 0, row);
          });
        });
      row.querySelectorAll('[data-drupal-selector$="copy"]').forEach((link) => {
        link.addEventListener("click", () => {
          window.setTimeout(copyPreviousDay, 0);
        });
      });
    });

    // A saved or AJAX-rebuilt form arrives without the marker; see above.
    reclaimLabel();
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
})(Drupal, once);
