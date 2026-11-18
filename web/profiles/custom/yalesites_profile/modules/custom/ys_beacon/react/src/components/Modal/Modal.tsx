import { createPortal } from "react-dom";
import { Stack } from "@fluentui/react";

import { ReactNode, useEffect, useRef } from "react";
import styles from "./Modal.module.css";

import { motion } from "framer-motion"

type ModalProps = {
  show: boolean;
  close: () => void;
  header: ReactNode;
  footer: ReactNode;
  children: ReactNode;
  variant: string | undefined;
  // Accessible name for the dialog (WCAG 4.1.2). Required for every modal so it
  // never announces as a bare "dialog".
  ariaLabel: string;
  // Return focus to whatever had it at open when this modal closes. Turn off
  // when the parent restores focus itself, because only the parent can do it
  // after it releases the page it made inert.
  restoreFocus?: boolean;
};

// Controls a keyboard user can Tab to, minus the tabOver sentinels.
const TABBABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]',
]
  .map((selector) => `${selector}:not([tabindex="-1"])`)
  .join(', ');

const getTabbables = (container: HTMLElement): HTMLElement[] =>
  (Array.from(container.querySelectorAll(TABBABLE)) as HTMLElement[]).filter(
    (el) => !el.classList.contains('tabOver')
  );

const Modal = ({ show, close, children, header, footer, variant, ariaLabel, restoreFocus = true }: ModalProps) => {
  const modalRef = useRef<HTMLElement>(null);
  // The listeners below are bound once at open; read the current close
  // callback through a ref so they never call a stale one.
  const closeRef = useRef(close);
  closeRef.current = close;

  // Wrap focus at either end of the dialog (WCAG 2.4.3 / 2.1.2). Checked
  // synchronously, before the browser moves focus, so it cannot race fast key
  // presses. Listening on the document also catches a Tab pressed while focus
  // is outside the dialog (on <body>, say) and brings it back in.
  // The tabOver sentinels only catch focus that slips past the trap (a screen
  // reader's virtual cursor, say) and send it to the other end.
  const focusEdge = (last: boolean) => {
    const tabbables = modalRef.current ? getTabbables(modalRef.current) : [];
    tabbables[last ? tabbables.length - 1 : 0]?.focus();
  };

  const handleKeyDown = (e: KeyboardEvent) => {
    const modal = modalRef.current;
    // A modal stacked on top of this one makes it inert; only the top dialog
    // handles Tab and Escape.
    if (!modal || modal.hasAttribute('inert')) {
      return;
    }
    // Escape closes only the top dialog: the citation overlay, not the chat
    // beneath it.
    if (e.key === 'Escape' && !e.isComposing) {
      e.preventDefault();
      closeRef.current();
      return;
    }
    if (e.key !== 'Tab') {
      return;
    }
    const tabbables = getTabbables(modal);
    const first = tabbables[0];
    const last = tabbables[tabbables.length - 1];
    const active = document.activeElement;
    const outside = active === modal || !modal.contains(active);

    if (outside || active === (e.shiftKey ? first : last)) {
      e.preventDefault();
      (e.shiftKey ? last : first)?.focus();
    }
  };

  // A control that becomes disabled or is removed while focused (Send, New
  // chat, Stop generating) drops focus to <body> with the dialog still open.
  // Catch that and put focus back on the dialog.
  const handleFocusOut = (e: FocusEvent) => {
    if (e.relatedTarget) {
      return;
    }
    queueMicrotask(() => {
      const modal = modalRef.current;
      const active = document.activeElement;
      if (modal?.isConnected && !modal.hasAttribute('inert') && (!active || active === document.body)) {
        modal.focus();
      }
    });
  };

  useEffect(() => {
    // Remember what had focus when this modal opened, so focus returns there on
    // close (WCAG 2.4.3). Captured before focusing the modal below.
    const opener = document.activeElement as HTMLElement | null;

    // If another modal is already open beneath this one (the citation overlay
    // stacks on top of the chat modal), make it inert so only the top dialog is
    // interactive and announced (WCAG 1.3.1 / 4.1.2). Restored on close.
    const inertedBelow = (
      Array.from(document.querySelectorAll('[modal-is-open="true"]')) as HTMLElement[]
    ).filter((el) => el !== modalRef.current && !el.hasAttribute('inert'));
    inertedBelow.forEach((el) => el.setAttribute('inert', ''));

    document.addEventListener('keydown', handleKeyDown);
    modalRef.current?.addEventListener('focusout', handleFocusOut);

    // focus the active modal
    modalRef.current?.focus();

    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      modalRef.current?.removeEventListener('focusout', handleFocusOut);
      // Un-inert before restoring focus so the target is focusable again.
      inertedBelow.forEach((el) => el.removeAttribute('inert'));
      if (restoreFocus) {
        // If the opener is gone (re-rendered away) or never took focus (a
        // browser that does not focus buttons on click), fall back to the
        // dialog beneath so focus never drops to <body>.
        const target =
          opener && opener !== document.body && opener.isConnected
            ? opener
            : inertedBelow[inertedBelow.length - 1];
        target?.focus?.();
      }
    };
  }, []);

  const handleClose = () => {
    close();
  };

  return createPortal(
    <>
      <div
        className={`${variant == 'citation' ? styles.modalContainerCitation : styles.modalContainer} ${show ? "show" : ""}`}
        onClick={() => handleClose()}
      >
        <section className={styles.modal} onClick={(e) => e.stopPropagation()} aria-modal={"true"} role={"dialog"} aria-label={ariaLabel} modal-is-open={"true"} ref={modalRef} tabIndex={-1}>
          <motion.div
            className={`${variant == 'citation' ? styles.modalCitation : styles.modalContent}`}
            initial={{ y: 50, opacity: 0, scale: 0.75 }}
            animate={{ y: 0, opacity: 1, scale: 1 }}
            transition={{ type: "spring", duration: 0.35 }}
          >
            <header className={`${variant == 'citation' ? styles.modalHeaderCitation : styles.modalHeader}`}>
              <Stack horizontal verticalAlign="center" horizontalAlign="space-between" className={styles.modalHeaderContainer}>
                {header}
                <Stack horizontal>
                  <div tabIndex={0} className="tabOver" onFocus={() => focusEdge(true)}></div>
                  <button className={styles.closeButton} onClick={handleClose} aria-label="Close modal">
                    <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                      <path d="M15.1719 2.42188L9.54688 8.04688L15.125 13.625C15.5938 14.0469 15.5938 14.75 15.125 15.1719C14.7031 15.6406 14 15.6406 13.5781 15.1719L7.95312 9.59375L2.375 15.1719C1.95312 15.6406 1.25 15.6406 0.828125 15.1719C0.359375 14.75 0.359375 14.0469 0.828125 13.5781L6.40625 8L0.828125 2.42188C0.359375 2 0.359375 1.29688 0.828125 0.828125C1.25 0.40625 1.95312 0.40625 2.42188 0.828125L8 6.45312L13.5781 0.875C14 0.40625 14.7031 0.40625 15.1719 0.875C15.5938 1.29688 15.5938 2 15.1719 2.42188Z" />
                    </svg>
                  </button>
                </Stack>
              </Stack>
            </header>
            <main className={`${variant == 'citation' ? styles.modalContentInnerCitation : styles.modalContentInner}`}>
              {children}

              {footer && (
                <div className={styles.modalFooter}>
                  {footer}
                </div>
              )}
            </main>
            <div tabIndex={0} className="tabOver" onFocus={() => focusEdge(false)}></div>
          </motion.div>
        </section>
      </div>
    </>,
    document.getElementById("ys-beacon-chat-widget") || document.createElement("div")
  );
};

export default Modal;
