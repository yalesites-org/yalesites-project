import { useMemo, useRef, type ReactNode } from "react";
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
    onCitationClicked: (citedDocument: Citation, citationIndex: number) => void;
}

export const Answer = ({
    answer,
    onCitationClicked
}: Props) => {
    const parsedAnswer = useMemo(() => parseAnswer(answer), [answer]);

    // Chat re-renders on every state change (opening or closing the citation
    // overlay included) and hands Answer new props each time. A new `sup`
    // renderer per render would make ReactMarkdown remount every superscript
    // button, so the one that opened the overlay would be gone when the overlay
    // tries to return focus to it (WCAG 2.4.3). Keep the renderer stable and
    // read the current citations and handler through a ref instead.
    const latest = useRef({ citations: parsedAnswer.citations, onCitationClicked });
    latest.current = { citations: parsedAnswer.citations, onCitationClicked };
    const markdownComponents = useMemo(() => ({
        ...demotedHeadingComponents,
        sup: ({ children }: { children?: ReactNode }) => {
            const text = Array.isArray(children) ? children.join("") : String(children ?? "");
            const n = Number(text);
            const citation = Number.isInteger(n) ? latest.current.citations[n - 1] : undefined;
            if (!citation) {
                // Non-citation superscript (e.g. genuine ^x^ markdown): leave inert.
                return <sup>{children}</sup>;
            }
            return (
                <sup>
                    <button
                        type="button"
                        className={styles.clickableSup}
                        onClick={() => latest.current.onCitationClicked(citation, n)}
                        aria-label={`View citation ${n}${citation.title ? `: ${citation.title}` : ""}`}
                        title={`Citation ${n}`}
                    >
                        {n}
                    </button>
                </sup>
            );
        },
    }), []);

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
                                    onClick={() => onCitationClicked(citation, idx + 1)}
                                    aria-label={citationLabel}>
                                        Citation
                                        <div className={styles.citation}>{idx + 1}</div>
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
                        components={markdownComponents}
                    />
                </Stack.Item>
            </Stack>
        </>
    );
};
