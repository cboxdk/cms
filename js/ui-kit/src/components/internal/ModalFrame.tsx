// The overlay a Dialog, a ConfirmDialog, a Drawer and the CommandPalette open in: React Aria's modal
// overlay, which renders in a portal, hides the rest of the page from a screen reader, keeps focus
// inside, closes on Escape, and returns focus to where it was when it closes. Internal to the kit.

import type { ReactNode } from 'react';
import { Modal, ModalOverlay } from 'react-aria-components/Modal';

import '../dialog.css';

export interface ModalFrameProps {
  readonly open: boolean;
  readonly onOpenChange: (open: boolean) => void;
  /** dialog centres it; drawer puts it at the end of the screen; palette near the top. */
  readonly kind: 'dialog' | 'drawer' | 'palette';
  /** Whether a press outside the overlay closes it; true but for a confirmation. */
  readonly dismissable: boolean;
  readonly children: ReactNode;
}

export function ModalFrame({ open, onOpenChange, kind, dismissable, children }: ModalFrameProps) {
  return (
    <ModalOverlay
      isOpen={open}
      onOpenChange={onOpenChange}
      isDismissable={dismissable}
      className={`cms-modal-overlay cms-modal-overlay--${kind}`}
    >
      <Modal className={`cms-modal cms-modal--${kind}`}>{children}</Modal>
    </ModalOverlay>
  );
}
