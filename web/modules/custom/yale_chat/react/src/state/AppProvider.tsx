import React, { createContext, useReducer, ReactNode } from 'react';
import { appStateReducer } from './AppReducer';
import {
  ChatHistoryLoadingState,
  CosmosDBHealth,
  CosmosDBStatus,
  FrontendSettings,
  Conversation,
  Feedback,
} from '../api';

export interface AppState {
  isChatHistoryOpen: boolean;
  chatHistoryLoadingState: ChatHistoryLoadingState;
  isCosmosDBAvailable: CosmosDBHealth;
  chatHistory: Conversation[] | null;
  filteredChatHistory: Conversation[] | null;
  currentChat: Conversation | null;
  frontendSettings: FrontendSettings | null;
  feedbackState: { [answerId: string]: Feedback.Neutral | Feedback.Positive | Feedback.Negative };
}

export type Action =
  | { type: 'TOGGLE_CHAT_HISTORY' }
  | { type: 'SET_COSMOSDB_STATUS'; payload: CosmosDBHealth }
  | { type: 'UPDATE_CHAT_HISTORY_LOADING_STATE'; payload: ChatHistoryLoadingState }
  | { type: 'UPDATE_CURRENT_CHAT'; payload: Conversation | null }
  | { type: 'UPDATE_FILTERED_CHAT_HISTORY'; payload: Conversation[] | null }
  | { type: 'UPDATE_CHAT_HISTORY'; payload: Conversation }
  | { type: 'UPDATE_CHAT_TITLE'; payload: Conversation }
  | { type: 'DELETE_CHAT_ENTRY'; payload: string }
  | { type: 'DELETE_CHAT_HISTORY' }
  | { type: 'DELETE_CURRENT_CHAT_MESSAGES'; payload: string }
  | { type: 'FETCH_CHAT_HISTORY'; payload: Conversation[] | null }
  | { type: 'FETCH_FRONTEND_SETTINGS'; payload: FrontendSettings | null }
  | { type: 'SET_FEEDBACK_STATE'; payload: { answerId: string; feedback: Feedback.Positive | Feedback.Negative | Feedback.Neutral } }
  | { type: 'GET_FEEDBACK_STATE'; payload: string };

// CosmosDB is never used; we stay on the direct conversationApi path always.
const initialState: AppState = {
  isChatHistoryOpen: false,
  chatHistoryLoadingState: ChatHistoryLoadingState.NotStarted,
  chatHistory: null,
  filteredChatHistory: null,
  currentChat: null,
  isCosmosDBAvailable: {
    cosmosDB: false,
    status: CosmosDBStatus.NotConfigured,
  },
  frontendSettings: null,
  feedbackState: {},
};

export const AppStateContext = createContext<
  { state: AppState; dispatch: React.Dispatch<Action> } | undefined
>(undefined);

type AppStateProviderProps = { children: ReactNode };

export const AppStateProvider: React.FC<AppStateProviderProps> = ({ children }) => {
  const [state, dispatch] = useReducer(appStateReducer, initialState);

  return (
    <AppStateContext.Provider value={{ state, dispatch }}>
      {children}
    </AppStateContext.Provider>
  );
};
