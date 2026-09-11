import { Action, AppState } from './AppProvider';

// Define the reducer function
export const appStateReducer = (state: AppState, action: Action): AppState => {
    switch (action.type) {
        case 'UPDATE_CURRENT_CHAT':
            return { ...state, currentChat: action.payload };
        default:
            return state;
      }
};
