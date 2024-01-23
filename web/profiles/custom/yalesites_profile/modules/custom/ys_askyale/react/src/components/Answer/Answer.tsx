import { useEffect, useMemo, useState } from "react";
import { useBoolean } from "@fluentui/react-hooks"
import { FontIcon, Stack, Text } from "@fluentui/react";
import aiAvatar from "../../assets/yaleLogo.svg";
 
import styles from "./Answer.module.css";

import { AskResponse, Citation } from "../../api";
import { parseAnswer } from "./AnswerParser";

import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import supersub from 'remark-supersub'

interface Props {
    answer: AskResponse;
    onCitationClicked: (citedDocument: Citation) => void;
}

export const Answer = ({
    answer,
    onCitationClicked
}: Props) => {
    const [isRefAccordionOpen, { toggle: toggleIsRefAccordionOpen }] = useBoolean(false);
    const filePathTruncationLimit = 50;

    const parsedAnswer = useMemo(() => parseAnswer(answer), [answer]);
    const [chevronIsExpanded, setChevronIsExpanded] = useState(isRefAccordionOpen);

    const handleChevronClick = () => {
        setChevronIsExpanded(!chevronIsExpanded);
        toggleIsRefAccordionOpen();
      };

    useEffect(() => {
        setChevronIsExpanded(isRefAccordionOpen);
    }, [isRefAccordionOpen]);

    const createCitationFilepath = (citation: Citation, index: number, truncate: boolean = false) => {
        // temporarily set citationFilename equal to Citation and index number.
        let citationFilename = `Citation ${index}`;

        // The following contextual variable declaration is commented out because the returned citactionFilename was not ideal. 

        // if (citation.filepath && citation.chunk_id) {
        //     if (truncate && citation.filepath.length > filePathTruncationLimit) {
        //         const citationLength = citation.filepath.length;
        //         citationFilename = `${citation.filepath.substring(0, 20)}...${citation.filepath.substring(citationLength -20)} - Part ${parseInt(citation.chunk_id) + 1}`;
        //     }
        //     else {
        //         citationFilename = `${citation.filepath} - Part ${parseInt(citation.chunk_id) + 1}`;
        //     }
        // }
        // else if (citation.filepath && citation.reindex_id) {
        //     citationFilename = `${citation.filepath} - Part ${citation.reindex_id}`;
        // }
        // else {
        //     citationFilename = `Citation ${index}`;
        // }
        return citationFilename;
    }

    return (
        <>
            <Stack className={styles.answerContainer} tabIndex={0}>
            {!!parsedAnswer.citations.length && (
                <Stack horizontal className={styles.answerHeader}>
                    <span className={styles.answerHeaderLabel}>References:</span>
                    <ul className={styles.citationList}>
                        {parsedAnswer.citations.map((citation, idx) => {
                            return (
                                <li key={idx}>
                                    <button 
                                    className={styles.citationContainer}
                                    title={createCitationFilepath(citation, ++idx)} 
                                    tabIndex={0} 
                                    role="button" 
                                    key={idx} 
                                    onClick={() => onCitationClicked(citation)} 
                                    onKeyDown={e => e.key === "Enter" || e.key === " " ? onCitationClicked(citation) : null}
                                    aria-label={createCitationFilepath(citation, idx)}>
                                        <div className={styles.citation}>{idx}</div>
                                        {createCitationFilepath(citation, idx, true)}
                                    </button>
                                </li>);
                        })}
                    </ul>
                </Stack>
            )}
            <img className={styles.chatMessageAIMessageAvatar} src={aiAvatar} alt="Yale Logo" />

                <Stack.Item grow>
                    <ReactMarkdown
                        linkTarget="_blank"
                        remarkPlugins={[remarkGfm, supersub]}
                        children={parsedAnswer.markdownFormatText}
                        className={styles.answerText}
                    />
                </Stack.Item>
            </Stack>
        </>
    );
};
