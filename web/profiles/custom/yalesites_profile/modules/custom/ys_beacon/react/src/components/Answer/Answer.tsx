import { useMemo, type ReactNode } from "react";
import { Stack } from "@fluentui/react";
import aiAvatar from "../../assets/yaleLogo.svg";
import styles from "./Answer.module.css";

import { AskResponse, Citation } from "../../api";
import { parseAnswer } from "./AnswerParser";
import { demotedHeadingComponents } from "../../constants/markdownComponents";

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
    const parsedAnswer = useMemo(() => parseAnswer(answer), [answer]);

    return (
        <>
            <Stack className={styles.answerContainer} tabIndex={0}>
            {!!parsedAnswer.citations.length && (
                <Stack horizontal className={styles.answerHeader}>
                    <span className={styles.answerHeaderLabel}>References:</span>
                    <ul className={styles.citationList}>
                        {parsedAnswer.citations.map((citation, idx) => {
                            const citationLabel = `Citation ${idx + 1}`;
                            return (
                                <li key={idx}>
                                    <button
                                    className={styles.citationContainer}
                                    title={citationLabel}
                                    onClick={() => onCitationClicked(citation)}
                                    aria-label={citationLabel}>
                                        <div className={styles.citation}>{idx + 1}</div>
                                        {citationLabel}
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
                        components={{
                            ...demotedHeadingComponents,
                            sup: ({ children }: { children?: ReactNode }) => {
                                const text = Array.isArray(children) ? children.join("") : String(children ?? "");
                                const n = Number(text);
                                const citation = Number.isInteger(n) ? parsedAnswer.citations[n - 1] : undefined;
                                if (!citation) {
                                    // Non-citation superscript (e.g. genuine ^x^ markdown): leave inert.
                                    return <sup>{children}</sup>;
                                }
                                return (
                                    <sup>
                                        <button
                                            type="button"
                                            className={styles.clickableSup}
                                            onClick={() => onCitationClicked(citation)}
                                            aria-label={`View citation ${n}${citation.title ? `: ${citation.title}` : ""}`}
                                            title={`Citation ${n}`}
                                        >
                                            {n}
                                        </button>
                                    </sup>
                                );
                            },
                        }}
                    />
                </Stack.Item>
            </Stack>
        </>
    );
};
