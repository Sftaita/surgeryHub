import React from 'react';
import { useIsDesktop } from '../useIsDesktop';

interface ModalProps {
  title: string;
  subtitle?: string;
  onClose: () => void;
  children: React.ReactNode;
  width?: number;
}

/**
 * Shared modal shell: bottom sheet on mobile (slide-up 300ms), centered
 * dialog on desktop >= 900px (fade+scale 220ms). Same pattern as every
 * sheet in SurgeryHub App v2 (day modal, wizard, hours, declare mission).
 */
export function Modal({ title, subtitle, onClose, children, width = 460 }: ModalProps) {
  const isDesktop = useIsDesktop();
  return (
    <>
      <div className="modal-overlay" onClick={onClose} />
      <div className={`modal-wrap ${isDesktop ? 'modal-wrap--desktop' : 'modal-wrap--mobile'}`}>
        <div
          className={`modal ${isDesktop ? 'modal--dialog' : 'modal--sheet'}`}
          style={isDesktop ? { width: `min(${width}px, 100%)` } : undefined}
          role="dialog"
          aria-modal="true"
          aria-labelledby="modal-title"
        >
          <div className="modal__header">
            <h3 className="modal__title" id="modal-title">{title}</h3>
            <button type="button" className="modal__close" aria-label="Fermer" onClick={onClose}>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round"><path d="M6 6l12 12M18 6 6 18" /></svg>
            </button>
          </div>
          {subtitle && <p className="modal__subtitle">{subtitle}</p>}
          {children}
        </div>
      </div>
    </>
  );
}
