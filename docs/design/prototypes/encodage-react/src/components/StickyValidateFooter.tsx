import React from 'react';

export function StickyValidateFooter({ onValidate }: { onValidate: () => void }) {
  return (
    <div className="sticky-footer">
      <button type="button" className="btn btn--dark" onClick={onValidate}>Valider l&apos;encodage</button>
    </div>
  );
}
