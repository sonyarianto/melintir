import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import './editor.css';

const el = document.getElementById('melintir-root');
if (el) {
  createRoot(el).render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  );
}
