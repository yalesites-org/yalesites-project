import styles from "./Layout.module.css";
import { useEffect, useState } from "react";
import Chat from "../../pages/chat/Chat";
import Footer from "../Footer/Footer";
import aiLogo from "../../assets/Logo.svg";
import Modal from "../../components/Modal/Modal";

const Layout = () => {
    const [isModalOpen, setIsModalOpen] = useState(false);

    const handleOpenModal = () => {
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
    /**
     * Close modal on escape key press.
     */
    useEffect(() => {
        const close = (e: { key: string; }) => {
          if(e.key === 'Escape'){
            handleCloseModal()
          }
        }
        window.addEventListener('keydown', close)
      return () => window.removeEventListener('keydown', close)
    },[])

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
        >
          <Chat />
        </Modal>
      )}
      <button
        type="button"
        id="ys-beacon-launch-modal"
        onClick={handleOpenModal}
        className="visually-hidden"
      >
        Try Beacon Now
      </button>
    </div>
  );
};

export default Layout;
