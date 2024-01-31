import { Outlet, Link } from "react-router-dom";
import styles from "./Layout.module.css";
import Contoso from "../../assets/Contoso.svg";
import { CopyRegular, ShareRegular } from "@fluentui/react-icons";
import { Dialog, Stack, TextField, ICommandBarStyles, IButtonStyles } from "@fluentui/react";
import { useContext, useEffect, useState } from "react";
import { HistoryButton, ShareButton } from "../../components/common/Button";
import { AppStateContext } from "../../state/AppProvider";
import { CosmosDBStatus } from "../../api";
import aiLogo from "../../assets/Logo.svg";
import Modal from "../../components/Modal/Modal";
import heroImage from "../../assets/heroImage.png";

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

    // Set const for modal footer content
    const LandingFooter = () => {
        return (
          <Stack.Item className={styles.answerDisclaimerContainer}>
            <div className={styles.answerDisclaimer}>
              <span className={styles.answerDisclaimerText}>Content is AI-generated and may contain inaccuracies. User discretion advised.</span>
              <span className={styles.answerDisclaimerSeparator}>|</span>
              <span className={styles.answerDisclaimerText}><a href="/#additional-insights" title="FAQs" onClick={handleCloseModal}>FAQs</a></span>
              <span className={styles.answerDisclaimerSeparator}>|</span>
              <span className={styles.answerDisclaimerText}><a href="/share-your-feedback" title="Share Feedback">Share feedback</a></span>
            </div>
          </Stack.Item>
        );
    }

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
        {isModalOpen && <Modal show={isModalOpen} header={<LandingHeader />} footer={<LandingFooter />} close={handleCloseModal} variant={''}><Outlet /></Modal>}
        <section className={styles.hero}>
          <div className={styles.heroText}>
            <h2>Introducing AI-Search at Yale</h2>
            <p>
              Experience the evolution of search with askYale and contribute to a more navigable and inclusive campus.
            </p>
            <button
            type="button"
            onClick={handleOpenModal}
            >
                Try askYale Now
            </button>
          </div>
          <figure className={styles.heroFigure}>
            <img src={heroImage} alt="askYale" className={styles.heroImage} />
            <figcaption className={styles.heroFigureCaption}><em>Untitled</em> by Jean-Michel Basquiat (American, 1960–1988). Yale University Art Gallery.</figcaption>
          </figure>
        </section>

    </div>
    );
};

export default Layout;
