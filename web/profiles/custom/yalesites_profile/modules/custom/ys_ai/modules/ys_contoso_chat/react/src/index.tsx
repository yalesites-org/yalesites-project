import React from 'react';
import ReactDOM from 'react-dom/client';
import { initializeIcons } from '@fluentui/react';
import Layout from './pages/layout/Layout';
import { AppStateProvider } from './state/AppProvider';
import './index.css';

initializeIcons();

export default function App() {
  return (
    <AppStateProvider>
      <Layout />
    </AppStateProvider>
  );
}

ReactDOM.createRoot(
  document.getElementById('yale-chat-widget') as HTMLElement,
).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
);
