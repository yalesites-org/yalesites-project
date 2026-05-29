import styles from "./Layout.module.css";
import { useContext, useEffect, useState } from "react";
import { AppStateContext } from "../../state/AppProvider";
import Chat from "../../pages/chat/Chat";
import Footer from "../Footer/Footer";
import aiLogo from "../../assets/Logo.svg";
import Modal from "../../components/Modal/Modal";

const Layout = () => {
    const [isSharePanelOpen, setIsSharePanelOpen] = useState<boolean>(false);
    const [copyClicked, setCopyClicked] = useState<boolean>(false);
    const [copyText, setCopyText] = useState<string>("Copy URL");
    const appStateContext = useContext(AppStateContext)

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

    const handleShareClick = () => {
        setIsSharePanelOpen(true);
    };

    const handleSharePanelDismiss = () => {
        setIsSharePanelOpen(false);
        setCopyClicked(false);
        setCopyText("Copy URL");
    };

    const handleCopyClick = () => {
        navigator.clipboard.writeText(window.location.href);
        setCopyClicked(true);
    };

    const handleHistoryClick = () => {
        appStateContext?.dispatch({ type: 'TOGGLE_CHAT_HISTORY' })
    };

    useEffect(() => {
        if (copyClicked) {
            setCopyText("Copied URL");
        }
    }, [copyClicked]);

    useEffect(() => { }, [appStateContext?.state.isCosmosDBAvailable.status]);

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
        id="launch-chat-modal"
        onClick={handleOpenModal}
        className="visually-hidden"
      >
        Try askYale Now
      </button>
    </div>
  );
};

export default Layout;
