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
        document.body.setAttribute('data-modal-active', 'true');
        document.body.setAttribute('data-body-frozen', 'true');
    };

    const handleCloseModal = () => {
        setIsModalOpen(false);
        document.body.removeAttribute('data-modal-active');
        document.body.removeAttribute('data-body-frozen');
    };

    const LandingHeader = () => {
        return (
            <img src={aiLogo} className={styles.modalHeaderTitle} alt="AskYale" />
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
        Try askYale Now
      </button>
    </div>
  );
};

export default Layout;
