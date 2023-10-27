import { Outlet, Link } from "react-router-dom";
import styles from "./Layout.module.css";
import { CommandBarButton, Dialog, Stack, TextField, ICommandBarStyles, IButtonStyles, DefaultButton  } from "@fluentui/react";
import { useContext, useEffect, useState } from "react";
import { HistoryButton } from "../../components/common/Button";
import { AppStateContext } from "../../state/AppProvider";
import { CosmosDBStatus } from "../../api";

import aiLogo from "../../assets/Logo.svg";
import closeButton from "../../assets/Close.svg";

const Layout = () => {
    // const [isSharePanelOpen, setIsSharePanelOpen] = useState<boolean>(false);
    // const [copyClicked, setCopyClicked] = useState<boolean>(false);
    // const [copyText, setCopyText] = useState<string>("Copy URL");
    const appStateContext = useContext(AppStateContext)
    
    const [isModalOpen, setIsModalOpen] = useState(false);
    
    const handleOpenModal = () => {
        setIsModalOpen(true);
    };
    
    const handleCloseModal = () => {
        setIsModalOpen(false);
    }; 
    
    const handleHistoryClick = () => {
        appStateContext?.dispatch({ type: 'TOGGLE_CHAT_HISTORY' })
    };

    useEffect(() => {}, [appStateContext?.state.isCosmosDBAvailable.status]);

    useEffect(() => {
        const close = (e) => {
          if(e.keyCode === 27){
            handleCloseModal();
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
        <section className={styles.modalCallout}>
            <div className={styles.modalCalloutContent}>
                <h2>Open the modal dialog</h2>
                <p>
                    Perspiciatis neque delectus voluptatum qui aut veniam voluptatem non.
                </p>
                <p>
                    Officia neque cum iure pariatur asperiores ab et nobis excepturi beatae at itaque sit. Tenetur perspiciatis nesciunt illum ut quisquam est et necessitatibus repellendus qui illo sint. Possimus aliquid quos dolor occaecati at maiores dolore. Qui corrupti animi facilis eos nostrum voluptatem. Perspiciatis ex sed dolor velit. Omnis est voluptatem illo iusto debitis ut sunt deleniti aut repellat.
                </p>
                <button
                type="button"
                onClick={handleOpenModal}
                >
                    Open dialog
                </button>
            </div>
        </section>
        
        {isModalOpen && <Modal onClose={handleCloseModal} />}
    </div>
    );
};

// Modal component
const Modal = ({ onClose }: { onClose: () => void }) => {
    return (
        <section className={styles.modal} aria-modal={"true"} role={"dialog"}>
            <div className={styles.modalContent}>
                <header className={styles.header} role={"banner"}>
                    <Stack horizontal verticalAlign="center" horizontalAlign="space-between" className={styles.headerContainer}>
                        <img src={aiLogo} className={styles.headerTitle} alt="AskYale" />

                        <Stack horizontal>
                            <button className={styles.closeButton} onClick={onClose}>
                                <img src={closeButton} className={styles.closeButtonIcon} />
                            </button>
                        </Stack>
                    </Stack>
                </header>
                <Outlet />
            </div>
        </section>
    );
};

export default Layout;
