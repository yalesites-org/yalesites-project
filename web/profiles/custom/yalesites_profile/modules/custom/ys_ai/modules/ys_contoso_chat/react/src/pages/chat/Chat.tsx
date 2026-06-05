import { useRef, useState, useEffect, useContext, useLayoutEffect } from 'react';
import { CommandBarButton, Dialog, DialogType, Stack } from '@fluentui/react';
import { SquareRegular, ErrorCircleRegular } from '@fluentui/react-icons';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import uuid from 'react-uuid';
import Modal from '../../components/Modal/Modal';
import Disclaimer from '../Disclaimer/Disclaimer';
import { isEmpty } from 'lodash-es';
import styles from './Chat.module.css';
import loading from '../../assets/loader-chat.gif';

import {
  ChatMessage,
  ConversationRequest,
  conversationApi,
  clearConversation,
  Citation,
  ToolMessageContent,
  ChatResponse,
  Conversation,
  ChatHistoryLoadingState,
  ErrorMessage,
} from '../../api';
import { Answer } from '../../components/Answer';
import { QuestionInput } from '../../components/QuestionInput';
import { AppStateContext } from '../../state/AppProvider';
import { useBoolean } from '@fluentui/react-hooks';

const WIDGET_ID = 'yale-chat-widget';

const enum messageStatus {
  NotRunning = 'Not Running',
  Processing = 'Processing',
  Done = 'Done',
}

const Chat = () => {
  const questionsFromData = document.getElementById(WIDGET_ID)?.getAttribute('data-initial-questions');
  const initialQuestions = questionsFromData ? JSON.parse(questionsFromData) : [];

  const appStateContext = useContext(AppStateContext);
  const chatMessageStreamEnd = useRef<HTMLDivElement | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [showLoadingMessage, setShowLoadingMessage] = useState<boolean>(false);
  const [activeCitation, setActiveCitation] = useState<Citation>();
  const [isCitationPanelOpen, setIsCitationPanelOpen] = useState<boolean>(false);
  const abortFuncs = useRef([] as AbortController[]);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [processMessages, setProcessMessages] = useState<messageStatus>(messageStatus.NotRunning);
  const [clearingChat, setClearingChat] = useState<boolean>(false);
  const [hideErrorDialog, { toggle: toggleErrorDialog }] = useBoolean(true);
  const [errorMsg, setErrorMsg] = useState<ErrorMessage | null>();
  const [providedQuestion, setProvidedQuestion] = useState<string>('');
  const [promptList, setPromptList] = useState<string[]>([]);
  const [promptsLoaded, setPromptsLoaded] = useState<boolean>(false);
  const [isModalOpen, setIsModalOpen] = useState(false);

  const errorDialogContentProps = {
    type: DialogType.close,
    title: errorMsg?.title,
    closeButtonAriaLabel: 'Close',
    subText: errorMsg?.subtitle,
  };

  const modalProps = {
    titleAriaId: 'labelId',
    subtitleAriaId: 'subTextId',
    isBlocking: true,
    styles: { main: { maxWidth: 450 } },
  };

  const [ASSISTANT, TOOL, ERROR] = ['assistant', 'tool', 'error'];

  let assistantMessage = {} as ChatMessage;
  let toolMessage = {} as ChatMessage;
  let assistantContent = '';

  const processResultMessage = (resultMessage: ChatMessage, userMessage: ChatMessage) => {
    if (resultMessage.role === ASSISTANT) {
      assistantContent += resultMessage.content;
      assistantMessage = resultMessage;
      assistantMessage.content = assistantContent;
    }
    if (resultMessage.role === TOOL) toolMessage = resultMessage;

    // Always include userMessage — we never rely on CosmosDB to persist it.
    isEmpty(toolMessage)
      ? setMessages([...messages, userMessage, assistantMessage])
      : setMessages([...messages, userMessage, toolMessage, assistantMessage]);
  };

  const makeApiRequest = async (question: string, conversationId?: string) => {
    setIsLoading(true);
    setShowLoadingMessage(true);
    const abortController = new AbortController();
    abortFuncs.current.unshift(abortController);

    const userMessage: ChatMessage = {
      id: uuid(),
      role: 'user',
      content: question,
      date: new Date().toISOString(),
    };

    let conversation: Conversation | null | undefined;
    if (!conversationId) {
      conversation = {
        id: uuid(),
        title: question,
        messages: [userMessage],
        date: new Date().toISOString(),
      };
    } else {
      conversation = appStateContext?.state?.currentChat;
      if (!conversation) {
        setIsLoading(false);
        setShowLoadingMessage(false);
        abortFuncs.current = abortFuncs.current.filter((a) => a !== abortController);
        return;
      }
      conversation.messages.push(userMessage);
    }

    appStateContext?.dispatch({ type: 'UPDATE_CURRENT_CHAT', payload: conversation });
    setMessages(conversation.messages);

    const request: ConversationRequest = {
      messages: [...conversation.messages.filter((m) => m.role !== ERROR)],
    };

    // Reset accumulated content for this request.
    assistantContent = '';
    assistantMessage = {} as ChatMessage;
    toolMessage = {} as ChatMessage;

    let result = {} as ChatResponse;
    try {
      const response = await conversationApi(request, abortController.signal, conversation.id);
      if (response?.body) {
        const reader = response.body.getReader();
        let runningText = '';

        while (true) {
          setProcessMessages(messageStatus.Processing);
          const { done, value } = await reader.read();
          if (done) break;

          const text = new TextDecoder('utf-8').decode(value);
          const objects = text.split('\n');
          objects.forEach((obj) => {
            try {
              if (obj !== '' && obj !== '{}') {
                runningText += obj;
                result = JSON.parse(runningText);
                if (result.choices?.length > 0) {
                  result.choices[0].messages.forEach((msg) => {
                    msg.id = msg.id || result.id;
                    msg.date = msg.date || new Date().toISOString();
                  });
                  if (result.choices[0].messages?.some((m) => m.role === ASSISTANT)) {
                    setShowLoadingMessage(false);
                  }
                  // Each chunk contains the full accumulated text; reset so
                  // processResultMessage doesn't double-append.
                  assistantContent = '';
                  result.choices[0].messages.forEach((resultObj) => {
                    processResultMessage(resultObj, userMessage);
                  });
                } else if (result.error) {
                  throw Error(result.error);
                }
                runningText = '';
              }
            } catch (e) {
              if (!(e instanceof SyntaxError)) {
                console.error(e);
                throw e;
              }
            }
          });
        }

        // Persist the tool message (carrying the citations) ahead of the
        // assistant message so the rendered References/superscripts can read
        // it via messages[index - 1]; dropping it leaves citations empty.
        isEmpty(toolMessage)
          ? conversation.messages.push(assistantMessage)
          : conversation.messages.push(toolMessage, assistantMessage);
        appStateContext?.dispatch({ type: 'UPDATE_CURRENT_CHAT', payload: conversation });
        setMessages([...conversation.messages]);
      }
    } catch (e) {
      if (!abortController.signal.aborted) {
        let errorMessage = 'An error occurred. Please try again. If the problem persists, please contact the site administrator.';
        if (result.error?.message) errorMessage = result.error.message;
        else if (typeof result.error === 'string') errorMessage = result.error;

        const errorChatMsg: ChatMessage = {
          id: uuid(),
          role: ERROR,
          content: errorMessage,
          date: new Date().toISOString(),
        };
        conversation.messages.push(errorChatMsg);
        appStateContext?.dispatch({ type: 'UPDATE_CURRENT_CHAT', payload: conversation });
        setMessages([...conversation.messages]);
      } else {
        setMessages([...conversation.messages]);
      }
    } finally {
      setIsLoading(false);
      setShowLoadingMessage(false);
      abortFuncs.current = abortFuncs.current.filter((a) => a !== abortController);
      setProcessMessages(messageStatus.Done);
    }

    return abortController.abort();
  };

  const clearChat = async () => {
    setClearingChat(true);
    const convId = appStateContext?.state.currentChat?.id;
    if (convId) {
      await clearConversation(convId);
    }
    appStateContext?.dispatch({ type: 'UPDATE_CURRENT_CHAT', payload: null });
    setMessages([]);
    setActiveCitation(undefined);
    setIsCitationPanelOpen(false);
    setClearingChat(false);
  };

  const newChat = () => {
    setProcessMessages(messageStatus.Processing);
    setMessages([]);
    setIsCitationPanelOpen(false);
    setActiveCitation(undefined);
    appStateContext?.dispatch({ type: 'UPDATE_CURRENT_CHAT', payload: null });
    setProcessMessages(messageStatus.Done);
  };

  const stopGenerating = () => {
    abortFuncs.current.forEach((a) => a.abort());
    setShowLoadingMessage(false);
    setIsLoading(false);
  };

  useEffect(() => {
    if (appStateContext?.state.currentChat) {
      setMessages(appStateContext.state.currentChat.messages);
    } else {
      setMessages([]);
    }
  }, [appStateContext?.state.currentChat]);

  useLayoutEffect(() => {
    chatMessageStreamEnd.current?.scrollIntoView({ behavior: 'smooth' });
  }, [showLoadingMessage, processMessages]);

  useEffect(() => {
    const questionPrompts = initialQuestions ?? [];
    const getRandomPrompts = (num: number) => {
      const shuffled = [...questionPrompts].sort(() => 0.5 - Math.random());
      return shuffled.slice(0, num);
    };
    if (!promptsLoaded) {
      setPromptList(getRandomPrompts(4));
      setPromptsLoaded(true);
    }
  }, []);

  const onShowCitation = (citation: Citation) => {
    setActiveCitation(citation);
    setIsCitationPanelOpen(true);
    setIsModalOpen(true);
  };

  const onViewSource = (citation: Citation) => {
    if (citation.url && !citation.url.includes('blob.core')) {
      window.open(citation.url, '_blank');
    }
  };

  const parseCitationFromMessage = (message: ChatMessage) => {
    if (message?.role === 'tool') {
      try {
        const toolMsg = JSON.parse(message.content) as ToolMessageContent;
        return toolMsg.citations;
      } catch {
        return [];
      }
    }
    return [];
  };

  const disabledButton = () =>
    isLoading ||
    !messages?.length ||
    clearingChat ||
    appStateContext?.state.chatHistoryLoadingState === ChatHistoryLoadingState.Loading;

  const CitationHeader = () => (
    <Stack
      aria-label="Citations Panel Header Container"
      horizontal
      className={styles.citationPanelHeaderContainer}
      horizontalAlign="space-between"
      verticalAlign="center"
    >
      <span aria-label="Citations" className={styles.citationPanelHeader}>
        Citations
      </span>
    </Stack>
  );

  return (
    <div className={isLoading ? styles.containerLoading : styles.container} role="main">
      <Stack horizontal className={styles.chatRoot}>
        <div className={messages.length < 1 ? styles.chatEmptyWrapper : styles.chatContainer}>
          {!messages || messages.length < 1 ? (
            <Stack className={styles.chatEmptyState}>
              <div className={styles.chatEmptyStateContainer}>
                <ul className={styles.chatPromptSuggestions}>
                  {promptList.map((prompt) => (
                    <li key={prompt}>
                      <button onClick={() => setProvidedQuestion(prompt)}>
                        <span>{prompt}</span>
                        <svg width="48" height="48" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                          <title>Ask any question</title>
                          <path d="M12.427 10.2108L39.5284 22.2175C41.4905 23.093 41.4905 25.907 39.5284 26.7825L12.427 38.7892C10.3423 39.7272 8.19622 37.3509 9.2999 35.2872L13.592 27.2203C13.8372 26.72 14.3278 26.3448 14.9409 26.2822L25.7324 24.9065C25.9164 24.9065 26.1003 24.7189 26.1003 24.4687C26.1003 24.2811 25.9164 24.0935 25.7324 24.0935L14.9409 22.7178C14.3278 22.5927 13.8372 22.28 13.592 21.7797L9.2999 13.7128C8.19622 11.6491 10.3423 9.27279 12.427 10.2108Z" />
                        </svg>
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            </Stack>
          ) : (
            <div
              className={styles.chatMessageStream}
              style={{ marginBottom: isLoading ? '40px' : '0px' }}
              role="log"
            >
              {messages.map((answer, index) => (
                <>
                  {answer.role === 'user' ? (
                    <div className={styles.chatMessageUser} tabIndex={0}>
                      <div className={styles.chatMessageUserMessage}>
                        <div className={styles.chatMessageUserMessageWrap}>{answer.content}</div>
                      </div>
                    </div>
                  ) : answer.role === 'assistant' ? (
                    <div className={styles.chatMessageGpt}>
                      <Answer
                        answer={{
                          answer: answer.content,
                          citations: parseCitationFromMessage(messages[index - 1]),
                        }}
                        onCitationClicked={(c) => onShowCitation(c)}
                      />
                    </div>
                  ) : answer.role === 'error' ? (
                    <div className={styles.chatMessageError}>
                      <Stack horizontal className={styles.chatMessageErrorContentHeader}>
                        <ErrorCircleRegular className={styles.errorIcon} />
                        <span>Error</span>
                      </Stack>
                      <span className={styles.chatMessageErrorContent}>{answer.content}</span>
                    </div>
                  ) : null}
                </>
              ))}
              {showLoadingMessage && (
                <div className={styles.chatMessageGpt}>
                  <Answer answer={{ answer: '&nbsp;', citations: [] }} onCitationClicked={() => null} />
                  <img className={styles.chatMessageLoading} src={loading} />
                </div>
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
                onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ' ? stopGenerating() : null)}
              >
                <SquareRegular className={styles.stopGeneratingIcon} aria-hidden="true" />
                <span className={styles.stopGeneratingText} aria-hidden="true">
                  Stop generating
                </span>
              </Stack>
            )}

            <QuestionInput
              clearOnSend
              placeholder="Ask any question..."
              disabled={isLoading}
              providedQuestion={providedQuestion}
              onSend={(question, id) => makeApiRequest(question, id)}
              conversationId={appStateContext?.state.currentChat?.id}
            />
          </Stack>

          <div style={{ display: 'flex', flexFlow: 'row nowrap', gap: '1rem', justifyContent: 'flex-start', alignItems: 'center', width: '100%' }}>
            <CommandBarButton
              role="button"
              text="New chat"
              iconProps={{ iconName: 'Add' }}
              onClick={newChat}
              disabled={disabledButton()}
              aria-label="Start a new chat"
              styles={{
                root: {
                  color: '#FFFFFF',
                  backgroundColor: 'hsl(213, 66%, 45%)',
                  height: '40px',
                  padding: '0 1.25rem',
                  borderRadius: '2rem',
                  border: 'none',
                  fontWeight: 600,
                  flexShrink: 0,
                  cursor: 'pointer',
                  selectors: {
                    ':focus-visible': { outline: '2px solid #286DC0', outlineOffset: '0.25rem' },
                  },
                },
                rootHovered: { color: '#FFFFFF', backgroundColor: 'hsl(210, 100%, 21%)' },
                rootPressed: { color: '#FFFFFF', backgroundColor: 'hsl(210, 100%, 21%)' },
                rootDisabled: { color: '#FFFFFF', backgroundColor: '#9fb0c3' },
                icon: { color: '#FFFFFF', fontSize: '1rem' },
                iconHovered: { color: '#FFFFFF' },
                iconPressed: { color: '#FFFFFF' },
                label: { color: '#FFFFFF', fontWeight: 600, margin: '0 0 0 0.25rem' },
              }}
            />
            <Disclaimer />
          </div>
        </div>

        {isModalOpen && (
          <Modal show={isModalOpen} header={<CitationHeader />} footer={null} close={() => setIsModalOpen(false)} variant="citation">
            {messages && messages.length > 0 && isCitationPanelOpen && activeCitation && (
              <Stack.Item className={styles.citationPanel} tabIndex={0} role="tabpanel" aria-label="Citations Panel">
                <div className={styles.citationPanelContentContainer}>
                  <h5
                    className={styles.citationPanelTitle}
                    role="link"
                    tabIndex={0}
                    title={activeCitation.url && !activeCitation.url.includes('blob.core') ? activeCitation.url : (activeCitation.title ?? '')}
                    onClick={() => onViewSource(activeCitation)}
                  >
                    {activeCitation.title}
                    <svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 28 28">
                      <path d="M22 14.5v5c0 2.484-2.016 4.5-4.5 4.5h-13c-2.484 0-4.5-2.016-4.5-4.5v-13c0-2.484 2.016-4.5 4.5-4.5h11c0.281 0 0.5 0.219 0.5 0.5v1c0 0.281-0.219 0.5-0.5 0.5h-11c-1.375 0-2.5 1.125-2.5 2.5v13c0 1.375 1.125 2.5 2.5 2.5h13c1.375 0 2.5-1.125 2.5-2.5v-5c0-0.281 0.219-0.5 0.5-0.5h1c0.281 0 0.5 0.219 0.5 0.5zM28 1v8c0 0.547-0.453 1-1 1-0.266 0-0.516-0.109-0.703-0.297l-2.75-2.75-10.187 10.187c-0.094 0.094-0.234 0.156-0.359 0.156s-0.266-0.063-0.359-0.156l-1.781-1.781c-0.094-0.094-0.156-0.234-0.156-0.359s0.063-0.266 0.156-0.359l10.187-10.187-2.75-2.75c-0.187-0.187-0.297-0.438-0.297-0.703 0-0.547 0.453-1 1-1h8c0.547 0 1 0.453 1 1z" />
                    </svg>
                  </h5>
                  {activeCitation.url && !activeCitation.url.includes('blob.core') && (
                    <p className={styles.citationPanelSourceUrl}>
                      <span className={styles.citationPanelLabel}>Source URL:</span>{' '}
                      <a href={activeCitation.url} target="_blank" rel="noopener noreferrer">
                        {activeCitation.url}
                      </a>
                    </p>
                  )}
                  <p className={styles.citationPanelLabel}>Document Content:</p>
                  <div tabIndex={0}>
                    <ReactMarkdown
                      linkTarget="_blank"
                      className={styles.citationPanelContent}
                      children={activeCitation.content}
                      remarkPlugins={[remarkGfm]}
                      components={{
                        // Force long URLs in the cited content to wrap inside
                        // the modal, independent of CSS-module class scoping.
                        a: ({ node, ...props }) => (
                          <a
                            {...props}
                            style={{
                              display: "inline-block",
                              maxWidth: "100%",
                              overflow: "hidden",
                              textOverflow: "ellipsis",
                              whiteSpace: "nowrap",
                              verticalAlign: "bottom",
                            }}
                          />
                        ),
                      }}
                    />
                  </div>
                </div>
              </Stack.Item>
            )}
          </Modal>
        )}
      </Stack>
    </div>
  );
};

export default Chat;
