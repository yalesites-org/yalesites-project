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
};

const Modal = ({ show, close, children, header, footer, variant}: ModalProps) => {
  const modalRef = useRef<HTMLDivElement>(null);
  const firstFocusableElement = useRef<HTMLElement | null>(null);
  const lastFocusableElement = useRef<HTMLElement | null>(null);

  const handleKeyDown = (e: KeyboardEvent) => {
    if (e.key === 'Tab' && modalRef.current) {
      setTimeout(() => {
        const focusedElement = modalRef.current?.querySelector(':focus');

        if (modalRef.current?.getAttribute('modal-is-open') === 'true') {
          const elements = modalRef.current?.querySelectorAll('button:not([aria-disabled="true"]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"]');
          firstFocusableElement.current = elements ? elements[0] as HTMLElement : null;
          lastFocusableElement.current = elements ? elements[elements.length - 1] as HTMLElement : null;
        }

        if (e.shiftKey && focusedElement?.isSameNode(firstFocusableElement.current)) {
          e.preventDefault();
          lastFocusableElement.current?.focus();
        } else if (!e.shiftKey && focusedElement?.isSameNode(lastFocusableElement.current)) {
          e.preventDefault();
          firstFocusableElement.current?.focus();
        }
      }, 1);
    }
  };

  useEffect(() => {
    modalRef.current?.addEventListener('keydown', handleKeyDown);

    // focus the active modal
    modalRef.current?.focus();

    return () => {
      modalRef.current?.removeEventListener('keydown', handleKeyDown);
    };
  }, []);

  const handleClose = () => {
    close();

    // Check if the first modal is still open and focus it
    const firstModal = document.querySelector('[modal-is-open="true"]');
    if (firstModal) {
      (firstModal as HTMLElement).focus();
    }
  };

  return createPortal(
    <>
      <div
        className={`${variant == 'citation' ? styles.modalContainerCitation : styles.modalContainer} ${show ? "show" : ""}`}
        onClick={() => handleClose()}
      >
        <section className={styles.modal} onClick={(e) => e.stopPropagation()} aria-modal={"true"} role={"dialog"} modal-is-open={"true"} ref={modalRef} tabIndex={0}>
          <motion.div
            className={`${variant == 'citation' ? styles.modalCitation : styles.modalContent}`}
            initial={{ y: 50, opacity: 0, scale: 0.75 }}
            animate={{ y: 0, opacity: 1, scale: 1 }}
            transition={{ type: "spring", duration: 0.35 }}
          >
            <header className={`${variant == 'citation' ? styles.modalHeaderCitation : styles.modalHeader}`} role={"banner"}>
              <Stack horizontal verticalAlign="center" horizontalAlign="space-between" className={styles.modalHeaderContainer}>
                {header}
                <Stack horizontal>
                  <button className={styles.closeButton} onClick={handleClose} aria-label="Close modal">
                    <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
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
          </motion.div>
        </section>
      </div>
    </>,
    document.getElementById("root") || document.createElement("div")
  );
};

export default Modal;
