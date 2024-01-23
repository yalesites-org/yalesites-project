import { Outlet, Link } from "react-router-dom";
import styles from "./Layout.module.css";
import { CommandBarButton, Dialog, Stack, TextField, ICommandBarStyles, IButtonStyles, DefaultButton  } from "@fluentui/react";
import { useContext, useEffect, useState, useRef } from "react";
import { HistoryButton } from "../../components/common/Button";
import { AppStateContext } from "../../state/AppProvider";
import { CosmosDBStatus } from "../../api";
import { motion } from "framer-motion"
import heroImage from "../../assets/heroImage.png";
import aiLogo from "../../assets/Logo.svg";

const Layout = () => {
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
    
    const handleHistoryClick = () => {
        appStateContext?.dispatch({ type: 'TOGGLE_CHAT_HISTORY' })
    };

    useEffect(() => {}, [appStateContext?.state.isCosmosDBAvailable.status]);

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

    const showHistory = () => {
        appStateContext?.state.isCosmosDBAvailable?.status !== CosmosDBStatus.NotConfigured && 
            <HistoryButton onClick={handleHistoryClick} text={appStateContext?.state?.isChatHistoryOpen ? "Hide chat history" : "Show chat history"}/>    
    }
    return (
    <div className={styles.layout}>
        <section className={styles.hero}>
          <div className={styles.heroText}>
            <h2>Your Voice Shapes the Future of AI at Yale</h2>
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
        
        {isModalOpen && <Modal onClose={handleCloseModal} />}
    </div>
    );
};

const Modal = ({ onClose }: { onClose: () => void }) => {
    const modalRef = useRef<HTMLDivElement | null>(null);
    const firstFocusableElement = useRef<HTMLElement | null>(null);
    const lastFocusableElement = useRef<HTMLElement | null>(null);

    const handleKeyDown = (e: KeyboardEvent) => {
        if (e.key === 'Tab' && modalRef.current) {
            setTimeout( () => {
                const focusedElement = modalRef.current?.querySelector(':focus');
                // console.log(focusedElement);

                if (modalRef.current?.getAttribute('modal-is-open') === 'true') {
                    const elements = modalRef.current?.querySelectorAll('button:not([aria-disabled="true"]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"]');
                    firstFocusableElement.current = elements? elements[0] as HTMLElement : null;
                    lastFocusableElement.current = elements? elements[elements.length - 1] as HTMLElement: null;
                    // console.log(elements);
                    // firstFocusableElement.current?.focus();
                }

                if (e.shiftKey && focusedElement?.isSameNode(firstFocusableElement.current)) {
                    e.preventDefault();
                    lastFocusableElement.current?.focus();
                    // console.log(`first focused reached`);
                } else if (!e.shiftKey && focusedElement?.isSameNode(lastFocusableElement.current)) {
                    e.preventDefault();
                    firstFocusableElement.current?.focus();
                    // console.log(`last focused reached`);
                }
        }, 1);
      }
    };

    useEffect(() => {
        modalRef.current?.addEventListener('keydown', handleKeyDown);

        return () => {
            modalRef.current?.removeEventListener('keydown', handleKeyDown);
        };
    }, []);
     
    return (
        <section className={styles.modal} aria-modal={"true"} role={"dialog"} modal-is-open={"true"} ref={modalRef} tabIndex={0}>
            <motion.div 
                className={styles.modalContent} 
                initial={{ y: 50, opacity: 0, scale: 0.75 }}
                animate={{ y: 0, opacity: 1, scale: 1 }} 
                transition={{ type: "spring", duration: 0.35 }}>
                <header className={styles.header} role={"banner"}>
                    <Stack horizontal verticalAlign="center" horizontalAlign="space-between" className={styles.headerContainer}>
                        <img src={aiLogo} className={styles.headerTitle} alt="AskYale" />

                        <Stack horizontal>
                            <button className={styles.closeButton} onClick={onClose} aria-label="Close modal">
                                <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M15.1719 2.42188L9.54688 8.04688L15.125 13.625C15.5938 14.0469 15.5938 14.75 15.125 15.1719C14.7031 15.6406 14 15.6406 13.5781 15.1719L7.95312 9.59375L2.375 15.1719C1.95312 15.6406 1.25 15.6406 0.828125 15.1719C0.359375 14.75 0.359375 14.0469 0.828125 13.5781L6.40625 8L0.828125 2.42188C0.359375 2 0.359375 1.29688 0.828125 0.828125C1.25 0.40625 1.95312 0.40625 2.42188 0.828125L8 6.45312L13.5781 0.875C14 0.40625 14.7031 0.40625 15.1719 0.875C15.5938 1.29688 15.5938 2 15.1719 2.42188Z"/>
                                </svg>
                            </button>
                        </Stack>
                    </Stack>
                </header>
                <Outlet />
                <Stack.Item className={styles.answerDisclaimerContainer}>
                    <div className={styles.answerDisclaimer}>
                        <span className={styles.answerDisclaimerText}>This chat is powered by artificial intelligence.</span>
                        <span className={styles.answerDisclaimerSeparator}>|</span> 
                        <span className={styles.answerDisclaimerText}><a href="/#additional-insights" title="FAQs" onClick={onClose}>FAQs</a></span>
                        <span className={styles.answerDisclaimerSeparator}>|</span> 
                        <span className={styles.answerDisclaimerText}><a href="/share-your-feedback" title="Share Feedback">Share feedback</a></span>
                    </div>
                </Stack.Item>
            </motion.div>
        </section>
    );
};

export default Layout;
