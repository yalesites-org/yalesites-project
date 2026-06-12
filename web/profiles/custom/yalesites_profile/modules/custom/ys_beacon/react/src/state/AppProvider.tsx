import React, { createContext, useReducer, ReactNode } from 'react';
import { appStateReducer } from './AppReducer';
import { Conversation } from '../api';

export interface AppState {
    currentChat: Conversation | null;
}

export type Action =
    | { type: 'UPDATE_CURRENT_CHAT', payload: Conversation | null };

const initialState: AppState = {
    currentChat: null,
};

export const AppStateContext = createContext<{
    state: AppState;
    dispatch: React.Dispatch<Action>;
  } | undefined>(undefined);

type AppStateProviderProps = {
    children: ReactNode;
  };

  export const AppStateProvider: React.FC<AppStateProviderProps> = ({ children }) => {
    const [state, dispatch] = useReducer(appStateReducer, initialState);

    return (
      <AppStateContext.Provider value={{ state, dispatch }}>
        {children}
      </AppStateContext.Provider>
    );
  };
