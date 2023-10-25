import { Outlet, Link } from "react-router-dom";
import styles from "./Layout.module.css";
import Azure from "../../assets/Azure.svg";
import { CopyRegular, ShareRegular } from "@fluentui/react-icons";
import { CommandBarButton, Dialog, Stack, TextField, ICommandBarStyles, IButtonStyles, DefaultButton  } from "@fluentui/react";
import { useContext, useEffect, useState } from "react";
import { HistoryButton, ShareButton } from "../../components/common/Button";
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

    const showHistory = () => {
        appStateContext?.state.isCosmosDBAvailable?.status !== CosmosDBStatus.NotConfigured && 
            <HistoryButton onClick={handleHistoryClick} text={appStateContext?.state?.isChatHistoryOpen ? "Hide chat history" : "Show chat history"}/>    
    }
    return (
    <div className={styles.layout}>
        <section className={styles.modalCallout} role={"modal"}>
            <button
            type="button"
            onClick={handleOpenModal}
            >
                Open dialog
            </button>
        </section>
        
        {isModalOpen && <Modal onClose={handleCloseModal} />}
        {/* <section className={styles.modalContent}>
            <header className={styles.header} role={"banner"}>
                <Stack horizontal verticalAlign="center" horizontalAlign="space-between" className={styles.headerContainer}>
                    <h2 className={styles.headerTitle}>AskYale</h2>
                    <Stack horizontal tokens={{ childrenGap: 4 }}>
                    </Stack>
                </Stack>
            </header>
            <Outlet />
        </section> */}
    </div>
    );
};

// Modal component
const Modal = ({ onClose }: { onClose: () => void }) => {
    return (
        <section className={styles.modal} aria-modal='true'>
            <div className={styles.modalContent}>
                <header className={styles.header} role={"banner"}>
                    <Stack horizontal verticalAlign="center" horizontalAlign="space-between" className={styles.headerContainer}>
                        <img src={aiLogo} className={styles.headerTitle} alt="AskYale" />

                        <Stack horizontal>
                            <span className="close" onClick={onClose}>
                                <img src={closeButton} className={styles.closeButton} />
                            </span>
                        </Stack>
                    </Stack>
                </header>
                <Outlet />
            </div>
        </section>
    );
};

export default Layout;
