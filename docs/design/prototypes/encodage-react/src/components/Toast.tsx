import React from 'react';

export function Toast({ message }: { message: string | null }) {
  return (
    <div className={`toast ${message ? 'toast--visible' : ''}`} role="status" aria-live="polite">
      {message}
    </div>
  );
}
