import { useRef, useState, useEffect, useContext, useLayoutEffect, useMemo } from "react";
import { Stack } from "@fluentui/react";
import { SquareRegular, ErrorCircleRegular } from "@fluentui/react-icons";
import ReactMarkdown from "react-markdown";
import remarkGfm from 'remark-gfm'
import rehypeRaw from "rehype-raw";
import uuid from 'react-uuid';
import DOMPurify from 'dompurify';
import Modal from "../../components/Modal/Modal";
import Disclaimer from "../Disclaimer/Disclaimer";
import { isEmpty } from "lodash-es";
import styles from "./Chat.module.css";
import loading from "../../assets/loader-chat.gif";

import {
    ChatMessage,
    ConversationRequest,
    conversationApi,
    getWidgetAttribute,
    Citation,
    ToolMessageContent,
    ChatResponse,
    Conversation
} from "../../api";
import { Answer } from "../../components/Answer";
import { QuestionInput } from "../../components/QuestionInput";
import { AppStateContext } from "../../state/AppProvider";
import { XSSAllowTags } from "../../constants/xssAllowTags";
import { demotedHeadingComponents } from "../../constants/markdownComponents";

const enum messageStatus {
    NotRunning = "Not Running",
    Processing = "Processing",
    Done = "Done"
}

// Ties the question input to the disclaimer via aria-describedby: the input sets
// aria-describedby to this id and the disclaimer renders with it.
const DISCLAIMER_ID = "ys-beacon-chat-disclaimer";

const Chat = () => {

    // Gets initial questions. Static per page load, so parse once.
    const initialQuestions = useMemo(() => {
        const questionsFromData = getWidgetAttribute('data-initial-questions');
        return questionsFromData ? JSON.parse(questionsFromData) : [];
    }, []);

    const appStateContext = useContext(AppStateContext)
    const chatMessageStreamEnd = useRef<HTMLDivElement | null>(null);
    const [isLoading, setIsLoading] = useState<boolean>(false);
    const [showLoadingMessage, setShowLoadingMessage] = useState<boolean>(false);
    const [activeCitation, setActiveCitation] = useState<Citation>();
    const [isCitationPanelOpen, setIsCitationPanelOpen] = useState<boolean>(false);
    const abortFuncs = useRef([] as AbortController[]);
    const [messages, setMessages] = useState<ChatMessage[]>([])
    const [processMessages, setProcessMessages] = useState<messageStatus>(messageStatus.NotRunning);
    const [providedQuestion, setProvidedQuestion] = useState<string>('')
    const [promptList, setPromptList] = useState<string[]>([])
    const [promptsLoaded, setpromptsLoaded] = useState<boolean>(false)

    const [isModalOpen, setIsModalOpen] = useState(false);

    const handleCloseModal = () => {
        setIsModalOpen(false);
    };

    // Only wire the input's aria-describedby / render the disclaimer when there
    // is disclaimer text; otherwise aria-describedby would point at an empty
    // element (WCAG 1.3.1). Static per page load, so read once (matches
    // initialQuestions above).
    const hasDisclaimer = useMemo(
        () => getWidgetAttribute("data-disclaimer").trim().length > 0,
        []
    );

    const [ASSISTANT, TOOL, ERROR] = ["assistant", "tool", "error"]

    let assistantMessage = {} as ChatMessage
    let toolMessage = {} as ChatMessage
    let assistantContent = ""

    const processResultMessage = (resultMessage: ChatMessage, userMessage: ChatMessage, conversationId?: string) => {
        if (resultMessage.role === ASSISTANT) {
            assistantContent += resultMessage.content
            assistantMessage = resultMessage
            assistantMessage.content = assistantContent
        }

        if (resultMessage.role === TOOL) toolMessage = resultMessage

        if (!conversationId) {
            isEmpty(toolMessage) ?
                setMessages([...messages, userMessage, assistantMessage]) :
                setMessages([...messages, userMessage, toolMessage, assistantMessage]);
        } else {
            isEmpty(toolMessage) ?
                setMessages([...messages, assistantMessage]) :
                setMessages([...messages, toolMessage, assistantMessage]);
        }
    }

    const makeApiRequest = async (question: string, conversationId?: string) => {
        setIsLoading(true);
        setShowLoadingMessage(true);
        const abortController = new AbortController();
        abortFuncs.current.unshift(abortController);

        const userMessage: ChatMessage = {
            id: uuid(),
            role: "user",
            content: question,
            date: new Date().toISOString(),
        };

        let conversation: Conversation | null | undefined;
        if (!conversationId) {
            conversation = {
                id: conversationId ?? uuid(),
                title: question,
                messages: [userMessage],
                date: new Date().toISOString(),
            }
        } else {
            conversation = appStateContext?.state?.currentChat
            if (!conversation) {
                console.error("Conversation not found.");
                setIsLoading(false);
                setShowLoadingMessage(false);
                abortFuncs.current = abortFuncs.current.filter(a => a !== abortController);
                return;
            } else {
                conversation.messages.push(userMessage);
            }
        }

        appStateContext?.dispatch({ type: 'UPDATE_CURRENT_CHAT', payload: conversation });
        setMessages(conversation.messages)

        const request: ConversationRequest = {
            messages: [...conversation.messages.filter((answer) => answer.role !== ERROR)]
        };

        let result = {} as ChatResponse;
        try {
            const response = await conversationApi(request, abortController.signal);
            if (response?.body) {
                const reader = response.body.getReader();
                const decoder = new TextDecoder("utf-8");
                let runningText = "";

                while (true) {
                    setProcessMessages(messageStatus.Processing)
                    const { done, value } = await reader.read();
                    if (done) break;

                    // Streaming mode keeps multibyte characters split across
                    // network chunks intact.
                    const text = decoder.decode(value, { stream: true });
                    const objects = text.split("\n");
                    objects.forEach((obj) => {
                        try {
                            if (obj !== "" && obj !== "{}") {
                                runningText += obj;
                                result = JSON.parse(runningText);
                                if (result.choices?.length > 0) {
                                    result.choices[0].messages.forEach((msg) => {
                                        msg.id = result.id;
                                        msg.date = new Date().toISOString();
                                    })
                                    if (result.choices[0].messages?.some(m => m.role === ASSISTANT)) {
                                        setShowLoadingMessage(false);
                                    }
                                    result.choices[0].messages.forEach((resultObj) => {
                                        processResultMessage(resultObj, userMessage, conversationId);
                                    })
                                }
                                else if (result.error) {
                                    throw Error(result.error);
                                }
                                runningText = "";
                            }
                        }
                        catch (e) {
                            if (!(e instanceof SyntaxError)) {
                                console.error(e);
                                throw e;
                            } else {
                                console.log("Incomplete message. Continuing...")
                            }
                        }
                    });
                }
                conversation.messages.push(toolMessage, assistantMessage)
                appStateContext?.dispatch({ type: 'UPDATE_CURRENT_CHAT', payload: conversation });
                setMessages([...messages, toolMessage, assistantMessage]);
            }

        } catch (e) {
            if (!abortController.signal.aborted) {
                let errorMessage = "An error occurred. Please try again. If the problem persists, please contact the site administrator.";
                if (result.error?.message) {
                    errorMessage = result.error.message;
                }
                else if (typeof result.error === "string") {
                    errorMessage = result.error;
                }
                let errorChatMsg: ChatMessage = {
                    id: uuid(),
                    role: ERROR,
                    content: errorMessage,
                    date: new Date().toISOString()
                }
                conversation.messages.push(errorChatMsg);
                appStateContext?.dispatch({ type: 'UPDATE_CURRENT_CHAT', payload: conversation });
                setMessages([...messages, errorChatMsg]);
            } else {
                setMessages([...messages, userMessage])
            }
        } finally {
            setIsLoading(false);
            setShowLoadingMessage(false);
            abortFuncs.current = abortFuncs.current.filter(a => a !== abortController);
            setProcessMessages(messageStatus.Done)
        }

        return abortController.abort();
    };

    const newChat = () => {
        setProcessMessages(messageStatus.Processing)
        setMessages([])
        setIsCitationPanelOpen(false);
        setActiveCitation(undefined);
        appStateContext?.dispatch({ type: 'UPDATE_CURRENT_CHAT', payload: null });
        setProcessMessages(messageStatus.Done)
    };

    const stopGenerating = () => {
        abortFuncs.current.forEach(a => a.abort());
        setShowLoadingMessage(false);
        setIsLoading(false);
    }

    useEffect(() => {
        if (appStateContext?.state.currentChat) {
            setMessages(appStateContext.state.currentChat.messages)
        } else {
            setMessages([])
        }
    }, [appStateContext?.state.currentChat]);

    useLayoutEffect(() => {
        if (appStateContext && appStateContext.state.currentChat && processMessages === messageStatus.Done) {
            setMessages(appStateContext.state.currentChat.messages)
            setProcessMessages(messageStatus.NotRunning)
        }
    }, [processMessages]);

    useLayoutEffect(() => {
        chatMessageStreamEnd.current?.scrollIntoView({ behavior: "smooth" })
    }, [showLoadingMessage, processMessages]);

    const onShowCitation = (citation: Citation) => {
        setActiveCitation(citation);
        setIsCitationPanelOpen(true);
        setIsModalOpen(true);
    };

    const parseCitationFromMessage = (message: ChatMessage) => {
        if (message?.role && message?.role === "tool") {
            try {
                const toolMessage = JSON.parse(message.content) as ToolMessageContent;
                return toolMessage.citations;
            }
            catch {
                return [];
            }
        }
        return [];
    }

    const disabledButton = () => {
        return isLoading || (messages && messages.length === 0)
    }

    // SuggestionButtons
    const handleButtonClick = (label: string) => {
        setProvidedQuestion(label)
    };

    useEffect(() => {
        /**
         * A list of possible prompts to show when the chat is empty.
         */

        const questionPrompts = initialQuestions ? initialQuestions : [];

        /**
         * Returns the first `num` prompts in the order the admin configured
         * them, so they always display in the same sequence they were entered.
         *
         * @param num The maximum number of prompts to return
         * @returns The configured prompts, in order, capped at `num`
         */
        const getInitialQuestionPrompts = (num: number | undefined) => {
            return questionPrompts.slice(0, num);
        }

        /**
         * A list of prompts to show when the chat is empty.
         */
        if (!promptsLoaded) {
            setPromptList(getInitialQuestionPrompts(4))
            setpromptsLoaded(true)
        }
    }, []);


    const CitationHeader = () => {
        return (
            <Stack aria-label="Citations Panel Header Container" horizontal className={styles.citationPanelHeaderContainer} horizontalAlign="space-between" verticalAlign="center">
                <h2 className={styles.citationPanelHeader}>Citations</h2>
            </Stack>
        );
    }

    return (
        <div className={isLoading ? styles.containerLoading : styles.container}>
            <Stack horizontal className={styles.chatRoot}>
                <div className={messages.length < 1 ? styles.chatEmptyWrapper: styles.chatContainer }>
                    {!messages || messages.length < 1 ? (
                        <Stack className={styles.chatEmptyState}>
                            <div className={styles.chatEmptyStateContainer}>
                                <ul className={styles.chatPromptSuggestions}>
                                {promptList.map((prompt) => (
                                    <li key={prompt}>
                                        <button key={prompt} onClick={() => handleButtonClick(prompt)}>
                                            <span>{prompt}</span>
                                            <svg width="48" height="48" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                                                <path d="M12.427 10.2108L39.5284 22.2175C41.4905 23.093 41.4905 25.907 39.5284 26.7825L12.427 38.7892C10.3423 39.7272 8.19622 37.3509 9.2999 35.2872L13.592 27.2203C13.8372 26.72 14.3278 26.3448 14.9409 26.2822L25.7324 24.9065C25.9164 24.9065 26.1003 24.7189 26.1003 24.4687C26.1003 24.2811 25.9164 24.0935 25.7324 24.0935L14.9409 22.7178C14.3278 22.5927 13.8372 22.28 13.592 21.7797L9.2999 13.7128C8.19622 11.6491 10.3423 9.27279 12.427 10.2108Z"/>
                                            </svg>
                                        </button>
                                    </li>)
                                )}
                                </ul>
                            </div>
                        </Stack>
                    ) : (
                        <div className={styles.chatMessageStream} style={{ marginBottom: isLoading ? "40px" : "0px" }} role="log" aria-busy={showLoadingMessage}>
                            {messages.map((answer, index) => (
                                <>
                                    {answer.role === "user" ? (
                                        <div className={styles.chatMessageUser} tabIndex={0} role="group" aria-label="user message">
                                            <div className={styles.chatMessageUserMessage}>
                                                <div className={styles.chatMessageUserMessageWrap}>
                                                    {answer.content}
                                                </div>
                                            </div>
                                        </div>
                                    ) : (
                                        answer.role === "assistant" ? <div className={styles.chatMessageGpt} role="group" aria-label="Beacon response">
                                            <Answer
                                                answer={{
                                                    answer: answer.content,
                                                    citations: parseCitationFromMessage(messages[index - 1]),
                                                }}
                                                onCitationClicked={c => onShowCitation(c)}
                                            />
                                        </div> : answer.role === "error" ? <div className={styles.chatMessageError} role="group" aria-label="Error message">
                                            <Stack horizontal className={styles.chatMessageErrorContentHeader}>
                                                <ErrorCircleRegular className={styles.errorIcon} />
                                                <span>Error</span>
                                            </Stack>
                                            <span className={styles.chatMessageErrorContent}>{answer.content}</span>
                                        </div> : null
                                    )}
                                </>
                            ))}
                            {showLoadingMessage && (
                                <>
                                    <div className={styles.chatMessageGpt}>
                                        <Answer
                                            answer={{
                                                answer: '&nbsp;',
                                                citations: []
                                            }}
                                            onCitationClicked={() => null}
                                        />
                                        <img className={styles.chatMessageLoading} src={loading}/>
                                    </div>
                                </>
                            )}
                            <div ref={chatMessageStreamEnd} className={styles.chatMessageStreamEnd} />
                        </div>
                    )}

                    <Stack horizontal className={styles.chatInput}>
                        {isLoading && (
                            <Stack
                                horizontal
                                className={styles.stopGeneratingContainer}
                                role="button"
                                aria-label="Stop generating"
                                tabIndex={0}
                                onClick={stopGenerating}
                                onKeyDown={e => e.key === "Enter" || e.key === " " ? stopGenerating() : null}
                                >
                                    <SquareRegular className={styles.stopGeneratingIcon} aria-hidden="true"/>
                                    <span className={styles.stopGeneratingText} aria-hidden="true">Stop generating</span>
                            </Stack>
                        )}

                        <QuestionInput
                            clearOnSend
                            placeholder="Ask any question..."
                            disabled={isLoading}
                            providedQuestion={providedQuestion}
                            describedById={hasDisclaimer ? DISCLAIMER_ID : undefined}
                            onSend={(question, id) => {
                                makeApiRequest(question, id)
                            }}
                            conversationId={appStateContext?.state.currentChat?.id}
                        />
                    </Stack>
                    <div style={{display: 'flex', flexFlow: 'row nowrap', gap: '1rem', justifyContent: 'flex-start', alignItems: 'center', width: '100%'}}>
                        <button
                            type="button"
                            className={styles.newChatButton}
                            onClick={newChat}
                            disabled={disabledButton()}
                        >
                            New chat
                        </button>
                        {hasDisclaimer && <Disclaimer id={DISCLAIMER_ID} />}
                    </div>
                </div>
                {/* Citation Panel */}
                {isModalOpen && <Modal show={isModalOpen} header={<CitationHeader />} footer={null} close={handleCloseModal} variant={'citation'} ariaLabel="Citations">

                {messages && messages.length > 0 && isCitationPanelOpen && activeCitation && (
                    <Stack.Item className={`${styles.citationPanel}`}>

                        <div className={`${styles.citationPanelContentContainer}`}>
                            {activeCitation.url && /^https?:\/\//i.test(activeCitation.url) && !activeCitation.url.includes("blob.core") ? (
                                <a className={styles.citationPanelTitle} href={activeCitation.url} target="_blank" rel="noopener noreferrer">
                                    {activeCitation.title}
                                    <span className={styles.visuallyHidden}> (opens in a new tab)</span>
                                    <svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 28 28" aria-hidden="true" focusable="false">
                                    <path d="M22 14.5v5c0 2.484-2.016 4.5-4.5 4.5h-13c-2.484 0-4.5-2.016-4.5-4.5v-13c0-2.484 2.016-4.5 4.5-4.5h11c0.281 0 0.5 0.219 0.5 0.5v1c0 0.281-0.219 0.5-0.5 0.5h-11c-1.375 0-2.5 1.125-2.5 2.5v13c0 1.375 1.125 2.5 2.5 2.5h13c1.375 0 2.5-1.125 2.5-2.5v-5c0-0.281 0.219-0.5 0.5-0.5h1c0.281 0 0.5 0.219 0.5 0.5zM28 1v8c0 0.547-0.453 1-1 1-0.266 0-0.516-0.109-0.703-0.297l-2.75-2.75-10.187 10.187c-0.094 0.094-0.234 0.156-0.359 0.156s-0.266-0.063-0.359-0.156l-1.781-1.781c-0.094-0.094-0.156-0.234-0.156-0.359s0.063-0.266 0.156-0.359l10.187-10.187-2.75-2.75c-0.187-0.187-0.297-0.438-0.297-0.703 0-0.547 0.453-1 1-1h8c0.547 0 1 0.453 1 1z"></path>
                                    </svg>
                                </a>
                            ) : (
                                <span className={styles.citationPanelTitle}>{activeCitation.title}</span>
                            )}
                            <div tabIndex={0}>
                            <ReactMarkdown
                                linkTarget="_blank"
                                className={styles.citationPanelContent}
                                children={DOMPurify.sanitize(activeCitation.content, { ALLOWED_TAGS: XSSAllowTags })}
                                remarkPlugins={[remarkGfm]}
                                rehypePlugins={[rehypeRaw]}
                                components={demotedHeadingComponents}
                            />
                            </div>
                        </div>
                    </Stack.Item>

                    )}
                </Modal>}
            </Stack>
        </div>
    );
};

export default Chat;
