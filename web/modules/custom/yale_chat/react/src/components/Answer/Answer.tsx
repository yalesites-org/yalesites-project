import { FormEvent, useEffect, useMemo, useState, useContext } from "react";
import { useBoolean } from "@fluentui/react-hooks"
import { Checkbox, DefaultButton, Dialog, FontIcon, Stack, Text } from "@fluentui/react";
import DOMPurify from 'dompurify';
import { AppStateContext } from '../../state/AppProvider';
import aiAvatar from "../../assets/yaleLogo.svg";
import styles from "./Answer.module.css";

import { AskResponse, Citation, Feedback, historyMessageFeedback } from "../../api";
import { parseAnswer } from "./AnswerParser";

import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import supersub from 'remark-supersub'
import { ThumbDislike20Filled, ThumbLike20Filled } from "@fluentui/react-icons";
import { XSSAllowTags } from "../../constants/xssAllowTags";

interface Props {
    answer: AskResponse;
    onCitationClicked: (citedDocument: Citation) => void;
}

export const Answer = ({
    answer,
    onCitationClicked
}: Props) => {
    const initializeAnswerFeedback = (answer: AskResponse) => {
        if (answer.message_id == undefined) return undefined;
        if (answer.feedback == undefined) return undefined;
        if (Object.values(Feedback).includes(answer.feedback)) return answer.feedback;
        return Feedback.Neutral;
    }

    const [isRefAccordionOpen, { toggle: toggleIsRefAccordionOpen }] = useBoolean(false);
    const filePathTruncationLimit = 50;

    const parsedAnswer = useMemo(() => parseAnswer(answer), [answer]);
    const [chevronIsExpanded, setChevronIsExpanded] = useState(isRefAccordionOpen);
    const [feedbackState, setFeedbackState] = useState(initializeAnswerFeedback(answer));
    const [isFeedbackDialogOpen, setIsFeedbackDialogOpen] = useState(false);
    const [showReportInappropriateFeedback, setShowReportInappropriateFeedback] = useState(false);
    const [negativeFeedbackList, setNegativeFeedbackList] = useState<Feedback[]>([]);
    const appStateContext = useContext(AppStateContext)
    const FEEDBACK_ENABLED = appStateContext?.state.frontendSettings?.feedback_enabled;

    const handleChevronClick = () => {
        setChevronIsExpanded(!chevronIsExpanded);
        toggleIsRefAccordionOpen();
      };

    useEffect(() => {
        setChevronIsExpanded(isRefAccordionOpen);
    }, [isRefAccordionOpen]);

    useEffect(() => {
        if (answer.message_id == undefined) return;

        let currentFeedbackState;
        if (appStateContext?.state.feedbackState && appStateContext?.state.feedbackState[answer.message_id]) {
            currentFeedbackState = appStateContext?.state.feedbackState[answer.message_id];
        } else {
            currentFeedbackState = initializeAnswerFeedback(answer);
        }
        setFeedbackState(currentFeedbackState)
    }, [appStateContext?.state.feedbackState, feedbackState, answer.message_id]);

    const createCitationFilepath = (citation: Citation, index: number, truncate: boolean = false) => {
      // temporarily set citationFilename equal to Citation and index number.
      let citationFilename = `Citation ${index}`;
      // let citationFilename = "";

      //   if (citation.filepath && citation.chunk_id) {
      //       if (truncate && citation.filepath.length > filePathTruncationLimit) {
      //           const citationLength = citation.filepath.length;
      //           citationFilename = `${citation.filepath.substring(0, 20)}...${citation.filepath.substring(citationLength -20)} - Part ${parseInt(citation.chunk_id) + 1}`;
      //       }
      //       else {
      //           citationFilename = `${citation.filepath} - Part ${parseInt(citation.chunk_id) + 1}`;
      //       }
      //   }
      //   else if (citation.filepath && citation.reindex_id) {
      //       citationFilename = `${citation.filepath} - Part ${citation.reindex_id}`;
      //   }
      //   else {
      //       citationFilename = `Citation ${index}`;
      //   }
        return citationFilename;
    }

    const onLikeResponseClicked = async () => {
        if (answer.message_id == undefined) return;

        let newFeedbackState = feedbackState;
        // Set or unset the thumbs up state
        if (feedbackState == Feedback.Positive) {
            newFeedbackState = Feedback.Neutral;
        }
        else {
            newFeedbackState = Feedback.Positive;
        }
        appStateContext?.dispatch({ type: 'SET_FEEDBACK_STATE', payload: { answerId: answer.message_id, feedback: newFeedbackState } });
        setFeedbackState(newFeedbackState);

        // Update message feedback in db
        await historyMessageFeedback(answer.message_id, newFeedbackState);
    }

    const onDislikeResponseClicked = async () => {
        if (answer.message_id == undefined) return;

        let newFeedbackState = feedbackState;
        if (feedbackState === undefined || feedbackState === Feedback.Neutral || feedbackState === Feedback.Positive) {
            newFeedbackState = Feedback.Negative;
            setFeedbackState(newFeedbackState);
            setIsFeedbackDialogOpen(true);
        } else {
            // Reset negative feedback to neutral
            newFeedbackState = Feedback.Neutral;
            setFeedbackState(newFeedbackState);
            await historyMessageFeedback(answer.message_id, Feedback.Neutral);
        }
        appStateContext?.dispatch({ type: 'SET_FEEDBACK_STATE', payload: { answerId: answer.message_id, feedback: newFeedbackState }});
    }

    const updateFeedbackList = (ev?: FormEvent<HTMLElement | HTMLInputElement>, checked?: boolean) => {
        if (answer.message_id == undefined) return;
        let selectedFeedback = (ev?.target as HTMLInputElement)?.id as Feedback;

        let feedbackList = negativeFeedbackList.slice();
        if (checked) {
            feedbackList.push(selectedFeedback);
        } else {
            feedbackList = feedbackList.filter((f) => f !== selectedFeedback);
        }

        setNegativeFeedbackList(feedbackList);
    };

    const onSubmitNegativeFeedback = async () => {
        if (answer.message_id == undefined) return;
        await historyMessageFeedback(answer.message_id, negativeFeedbackList.join(","));
        resetFeedbackDialog();
    }

    const resetFeedbackDialog = () => {
        setIsFeedbackDialogOpen(false);
        setShowReportInappropriateFeedback(false);
        setNegativeFeedbackList([]);
    }

    const UnhelpfulFeedbackContent = () => {
        return (<>
            <div>Why wasn't this response helpful?</div>
            <Stack tokens={{childrenGap: 4}}>
                <Checkbox label="Citations are missing" id={Feedback.MissingCitation} defaultChecked={negativeFeedbackList.includes(Feedback.MissingCitation)} onChange={updateFeedbackList}></Checkbox>
                <Checkbox label="Citations are wrong" id={Feedback.WrongCitation} defaultChecked={negativeFeedbackList.includes(Feedback.WrongCitation)} onChange={updateFeedbackList}></Checkbox>
                <Checkbox label="The response is not from my data" id={Feedback.OutOfScope} defaultChecked={negativeFeedbackList.includes(Feedback.OutOfScope)} onChange={updateFeedbackList}></Checkbox>
                <Checkbox label="Inaccurate or irrelevant" id={Feedback.InaccurateOrIrrelevant} defaultChecked={negativeFeedbackList.includes(Feedback.InaccurateOrIrrelevant)} onChange={updateFeedbackList}></Checkbox>
                <Checkbox label="Other" id={Feedback.OtherUnhelpful} defaultChecked={negativeFeedbackList.includes(Feedback.OtherUnhelpful)} onChange={updateFeedbackList}></Checkbox>
            </Stack>
            <div onClick={() => setShowReportInappropriateFeedback(true)} style={{ color: "#115EA3", cursor: "pointer"}}>Report inappropriate content</div>
        </>);
    }

    const ReportInappropriateFeedbackContent = () => {
        return (
            <>
                <div>The content is <span style={{ color: "red" }} >*</span></div>
                <Stack tokens={{childrenGap: 4}}>
                    <Checkbox label="Hate speech, stereotyping, demeaning" id={Feedback.HateSpeech} defaultChecked={negativeFeedbackList.includes(Feedback.HateSpeech)} onChange={updateFeedbackList}></Checkbox>
                    <Checkbox label="Violent: glorification of violence, self-harm" id={Feedback.Violent} defaultChecked={negativeFeedbackList.includes(Feedback.Violent)} onChange={updateFeedbackList}></Checkbox>
                    <Checkbox label="Sexual: explicit content, grooming" id={Feedback.Sexual} defaultChecked={negativeFeedbackList.includes(Feedback.Sexual)} onChange={updateFeedbackList}></Checkbox>
                    <Checkbox label="Manipulative: devious, emotional, pushy, bullying" defaultChecked={negativeFeedbackList.includes(Feedback.Manipulative)} id={Feedback.Manipulative} onChange={updateFeedbackList}></Checkbox>
                    <Checkbox label="Other" id={Feedback.OtherHarmful} defaultChecked={negativeFeedbackList.includes(Feedback.OtherHarmful)} onChange={updateFeedbackList}></Checkbox>
                </Stack>
            </>
        );
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
