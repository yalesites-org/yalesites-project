import styles from "./Layout.module.css";
import { useEffect, useRef, useState } from "react";
import Chat from "../../pages/chat/Chat";
import Footer from "../Footer/Footer";
import aiLogo from "../../assets/Logo.svg";
import Modal from "../../components/Modal/Modal";

// The first Beacon launch link a visitor can see, else the first one at all.
const firstVisibleLaunchLink = () => {
    const links = Array.from(
        document.querySelectorAll<HTMLElement>('a[href="#launch-chat"]')
    );
    return links.find((link) => link.getClientRects().length > 0) ?? links[0] ?? null;
};

const Layout = () => {
    const [isModalOpen, setIsModalOpen] = useState(false);
    // The Beacon link that launched the chat. js/events.js clicks the hidden
    // launch button without moving focus, so this is the link the visitor
    // used, or <body> when a #launch-chat URL opened the chat on page load.
    const openerRef = useRef<HTMLElement | null>(null);

    const handleOpenModal = () => {
        openerRef.current = document.activeElement as HTMLElement | null;
        setIsModalOpen(true);
    };

    const handleCloseModal = () => {
        setIsModalOpen(false);
    };

    /**
     * Freeze the underlying page while the modal is open and restore the
     * scroll position when it closes. Pinning the body with position: fixed
     * (driven by the data-body-frozen attribute, see index.css) is what stops
     * touch scrolling from leaking through to the page on mobile; the stored
     * top offset keeps the page from jumping to the top on open/close.
     */
    useEffect(() => {
        if (!isModalOpen) {
            return;
        }
        const scrollY = window.scrollY;
        document.body.setAttribute('data-modal-active', 'true');
        document.body.setAttribute('data-body-frozen', 'true');
        document.body.style.top = `-${scrollY}px`;

        // Remove the underlying page from the accessibility tree and the tab
        // order while the modal is open, so the dialog is a true modal context
        // (WCAG 1.3.1 / 4.1.2). Everything except the Beacon widget host (which
        // holds the modal) is marked inert; only elements this effect set are
        // cleared on close, so any pre-existing inert stays untouched.
        const widget = document.getElementById('ys-beacon-chat-widget');
        const inerted: HTMLElement[] = [];
        Array.from(document.body.children).forEach((child) => {
            if (child === widget || child.tagName === 'SCRIPT' || child.tagName === 'STYLE') {
                return;
            }
            const el = child as HTMLElement;
            if (!el.hasAttribute('inert')) {
                el.setAttribute('inert', '');
                inerted.push(el);
            }
        });

        return () => {
            document.body.removeAttribute('data-modal-active');
            document.body.removeAttribute('data-body-frozen');
            document.body.style.top = '';
            window.scrollTo(0, scrollY);
            inerted.forEach((el) => el.removeAttribute('inert'));

            // Return focus here, not in the Modal: React runs the Modal's
            // cleanup first, while the page is still inert, so a focus() call
            // there is ignored (WCAG 2.4.3). With no launching link (a
            // #launch-chat page load), use the first visible Beacon link.
            const opener = openerRef.current;
            const target =
                opener && opener !== document.body && opener.isConnected
                    ? opener
                    : firstVisibleLaunchLink();
            target?.focus({ preventScroll: true });
        };
    }, [isModalOpen]);

    const LandingHeader = () => {
        return (
            <>
                <h2 className={styles.visuallyHidden}>Beacon chat</h2>
                <img src={aiLogo} className={styles.modalHeaderTitle} alt="Beacon" />
            </>
        );
    }
    return (
    <div className={styles.layout}>
      {isModalOpen && (
        <Modal
          show={isModalOpen}
          header={<LandingHeader />}
          footer={<Footer />}
          close={handleCloseModal}
          variant={""}
          ariaLabel="Beacon chat"
          restoreFocus={false}
        >
          <Chat />
        </Modal>
      )}
      {/*
        Programmatic launch hook only: js/events.js calls .click() on this
        button when a visitor follows a #launch-chat link. Visitors never reach
        it directly, so it is hidden from assistive technology and removed from
        the tab order (YaleSites-Internal#1944: axe "region" rule).
      */}
      <button
        type="button"
        id="ys-beacon-launch-modal"
        onClick={handleOpenModal}
        className="visually-hidden"
        aria-hidden="true"
        tabIndex={-1}
      >
        Try Beacon Now
      </button>
    </div>
  );
};

export default Layout;
