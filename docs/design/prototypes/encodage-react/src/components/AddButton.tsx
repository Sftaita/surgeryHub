import React from 'react';

interface AddButtonProps {
  children: React.ReactNode;
  onClick: () => void;
  fullWidth?: boolean;
}

export function AddButton({ children, onClick, fullWidth = true }: AddButtonProps) {
  return (
    <button type="button" className={`add-btn ${fullWidth ? 'add-btn--full' : ''}`} onClick={onClick}>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round"><path d="M12 5v14M5 12h14" /></svg>
      {children}
    </button>
  );
}
