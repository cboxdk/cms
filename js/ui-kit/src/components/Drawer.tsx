import type { ReactNode } from 'react';
import { Dialog as AriaDialog } from 'react-aria-components/Dialog';
import { Heading } from 'react-aria-components/Heading';

import { useKitTranslation } from '../i18n/translations';
import { IconButton } from './IconButton';
import { ModalFrame } from './internal/ModalFrame';

import './dialog.css';

/**
 * The props of Drawer.
 *
 * @experimental
 */
export interface DrawerProps {
  /** The drawer's heading, which names it, from the caller's translations. */
  readonly title: string;
  /** Whether the drawer is open; the drawer is controlled. */
  readonly open: boolean;
  /** Called with false when the drawer closes by Escape, a press outside it or its close button. */
  readonly onOpenChange: (open: boolean) => void;
  /** The drawer's content. */
  readonly children: ReactNode;
  /** The drawer's buttons, below its content. */
  readonly footer?: ReactNode;
}

/**
 * A modal panel at the end of the screen for work beside the page, such as the details of a row
 * of a table: a dialog as Dialog is, with the same keyboard, that takes the screen's height and
 * its full width on a phone.
 *
 * @experimental
 */
export function Drawer({ title, open, onOpenChange, children, footer }: DrawerProps) {
  const t = useKitTranslation();

  return (
    <ModalFrame open={open} onOpenChange={onOpenChange} kind="drawer" dismissable>
      <AriaDialog className="cms-dialog">
        <div className="cms-dialog__header">
          <Heading slot="title" className="cms-dialog__title">
            {title}
          </Heading>
          <IconButton
            label={t('kit.close')}
            icon="close"
            onClick={() => {
              onOpenChange(false);
            }}
          />
        </div>
        <div className="cms-dialog__body">{children}</div>
        {footer === undefined ? null : <div className="cms-dialog__footer">{footer}</div>}
      </AriaDialog>
    </ModalFrame>
  );
}
