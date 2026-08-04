import * as React from "react";
import styles from "./prestationsDesign.module.css";
import { CloseIcon, CheckIcon } from "./prestationsIcons";

/** Primitives visuelles dédiées à Catalogue > Prestations, portées depuis le
 *  prototype de référence (react-catalogue-prestations) — chrome de modale,
 *  bouton, switch, pastille de statut. Volontairement séparées de la
 *  bibliothèque MUI partagée : ce module cible une fidélité visuelle exacte
 *  au design fourni, pas la réutilisation cross-page. */

export function Modal({
  title, subtitle, onClose, children, width = 480,
}: {
  title: string;
  subtitle?: string;
  onClose: () => void;
  children: React.ReactNode;
  width?: number;
}) {
  return (
    <>
      <div className={styles.modalOverlay} onClick={onClose} />
      <div className={styles.modalWrap}>
        <div className={styles.modal} style={{ width: `min(${width}px, 100%)` }} role="dialog" aria-modal="true">
          <div className={styles.modalHeader}>
            <h3 className={styles.modalTitle}>{title}</h3>
            <button type="button" className={styles.modalClose} aria-label="Fermer" onClick={onClose}>
              <CloseIcon size={15} />
            </button>
          </div>
          {subtitle && <p className={styles.modalSubtitle}>{subtitle}</p>}
          {children}
        </div>
      </div>
    </>
  );
}

type BtnVariant = "primary" | "secondary" | "ghost" | "outline";

export function Btn({
  variant = "primary", fullWidth, small, className, children, ...rest
}: {
  variant?: BtnVariant;
  fullWidth?: boolean;
  small?: boolean;
} & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  const variantClass = {
    primary: styles.btnPrimary,
    secondary: styles.btnSecondary,
    ghost: styles.btnGhost,
    outline: styles.btnOutline,
  }[variant];
  const classes = [styles.btn, variantClass, fullWidth ? styles.btnFull : "", small ? styles.btnSm : "", className ?? ""]
    .filter(Boolean)
    .join(" ");
  return (
    <button type="button" className={classes} {...rest}>
      {children}
    </button>
  );
}

export function Switch({ label, checked, onChange, disabled }: { label: string; checked: boolean; onChange: (checked: boolean) => void; disabled?: boolean }) {
  return (
    <div className={styles.switchRow}>
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        aria-label={label}
        disabled={disabled}
        className={[styles.switchBox, checked ? styles.switchBoxOn : ""].filter(Boolean).join(" ")}
        onClick={() => onChange(!checked)}
      >
        {checked && <CheckIcon size={12} />}
      </button>
      <span className={styles.switchLabel} onClick={() => !disabled && onChange(!checked)}>{label}</span>
    </div>
  );
}

export function StatusPill({ active, activeLabel = "Active", inactiveLabel = "Inactive" }: { active: boolean; activeLabel?: string; inactiveLabel?: string }) {
  return (
    <span className={[styles.statusPill, active ? styles.statusPillActive : styles.statusPillInactive].join(" ")}>
      {active ? activeLabel : inactiveLabel}
    </span>
  );
}

export function Tag({ children }: { children: React.ReactNode }) {
  return <span className={[styles.tag, styles.tagNeutral].join(" ")}>{children}</span>;
}
