import { useEffect, useState } from "react";
import { Stack, TextField } from "@fluentui/react";
import { SendRegular } from "@fluentui/react-icons";
import Send from "../../assets/Send.svg";
import styles from "./QuestionInput.module.css";

interface Props {
    onSend: (question: string, id?: string) => void;
    disabled: boolean;
    placeholder?: string;
    clearOnSend?: boolean;
    conversationId?: string;
    providedQuestion?: string;
}

export const QuestionInput = ({ onSend, disabled, placeholder, clearOnSend, conversationId, providedQuestion }: Props) => {
    const [question, setQuestion] = useState<string>(providedQuestion as string);

    /**
     * If a question is provided, set it as the question state.
     */
    useEffect(() => {
        if(providedQuestion && providedQuestion?.length > 0) {
            onSend(providedQuestion as string);
        }
    }, [providedQuestion])

    const sendQuestion = () => {
        if (disabled || !question.trim()) {
            return;
        }

        if(conversationId){
            onSend(question, conversationId);
        }else{
            onSend(question);
        }

        if (clearOnSend) {
            setQuestion("");
        }
    };

    const onEnterPress = (ev: React.KeyboardEvent<Element>) => {
        if (ev.key === "Enter" && !ev.shiftKey) {
            ev.preventDefault();
            sendQuestion();
        }
    };

    const onQuestionChange = (_ev: React.FormEvent<HTMLInputElement | HTMLTextAreaElement>, newValue?: string) => {
        setQuestion(newValue || "");
    };

    const sendQuestionDisabled = disabled || !question.trim();

    return (
        <Stack horizontal className={styles.questionInputContainer}>
            <TextField
                className={styles.questionInputTextArea}
                placeholder={placeholder}
                multiline
                resizable={false}
                borderless
                value={question}
                onChange={onQuestionChange}
                onKeyDown={onEnterPress}
                inputClassName={styles.questionInputItem}
            />
            <button className={styles.questionInputSendButtonContainer}
                aria-label="Ask question button"
                onClick={sendQuestion}
                onKeyDown={e => e.key === "Enter" || e.key === " " ? sendQuestion() : null}
                aria-disabled= {sendQuestionDisabled}
            >
                { sendQuestionDisabled ?
                    <span className={styles.questionInputSendButtonDisabled}>
                         <svg width="48" height="48" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                            <title>Ask any question</title>
                            <path d="M12.427 10.2108L39.5284 22.2175C41.4905 23.093 41.4905 25.907 39.5284 26.7825L12.427 38.7892C10.3423 39.7272 8.19622 37.3509 9.2999 35.2872L13.592 27.2203C13.8372 26.72 14.3278 26.3448 14.9409 26.2822L25.7324 24.9065C25.9164 24.9065 26.1003 24.7189 26.1003 24.4687C26.1003 24.2811 25.9164 24.0935 25.7324 24.0935L14.9409 22.7178C14.3278 22.5927 13.8372 22.28 13.592 21.7797L9.2999 13.7128C8.19622 11.6491 10.3423 9.27279 12.427 10.2108Z"/>
                        </svg>
                    </span>
                    :
                    <span className={styles.questionInputSendButton}>
                        <svg width="48" height="48" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                        <title>Ask any question</title>
                            <path d="M12.427 10.2108L39.5284 22.2175C41.4905 23.093 41.4905 25.907 39.5284 26.7825L12.427 38.7892C10.3423 39.7272 8.19622 37.3509 9.2999 35.2872L13.592 27.2203C13.8372 26.72 14.3278 26.3448 14.9409 26.2822L25.7324 24.9065C25.9164 24.9065 26.1003 24.7189 26.1003 24.4687C26.1003 24.2811 25.9164 24.0935 25.7324 24.0935L14.9409 22.7178C14.3278 22.5927 13.8372 22.28 13.592 21.7797L9.2999 13.7128C8.19622 11.6491 10.3423 9.27279 12.427 10.2108Z"/>
                        </svg>
                    </span>
                }
            </button>
        </Stack>
    );
};
