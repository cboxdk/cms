import { Button } from 'react-aria-components/Button';
import { Text } from 'react-aria-components/Text';
import {
  UNSTABLE_Toast as AriaToast,
  UNSTABLE_ToastContent as AriaToastContent,
  UNSTABLE_ToastRegion as AriaToastRegion,
} from 'react-aria-components/Toast';
import { ToastQueue } from 'react-stately';

import { useKitTranslation } from '../i18n/translations';
import type { CalloutTone } from './Callout';
import { Icon, type IconName } from './Icon';

import './toast.css';

/**
 * A toast: a short message about something that happened.
 *
 * @experimental
 */
export interface KitToast {
  /** What happened, such as "The role is saved", from the caller's translations. */
  readonly title: string;
  /** More about what happened, from the caller's translations. */
  readonly description?: string | undefined;
  /** info, the default, success, warning or danger. */
  readonly tone?: CalloutTone | undefined;
}

/**
 * How long a toast stays.
 *
 * @experimental
 */
export interface KitToastOptions {
  /**
   * How long the toast stays, in milliseconds, at least 5000; undefined keeps it until it is
   * closed. A toast that asks the reader to act should not go away by itself.
   */
  readonly timeout?: number | undefined;
}

/**
 * The toasts of a page: a ToastRegion shows them, and anything on the page may add one.
 *
 * @experimental
 */
export interface KitToastQueue {
  /** Shows a toast and returns its key. */
  add(toast: KitToast, options?: KitToastOptions): string;
  /** Closes the toast with the key add gave. */
  close(key: string): void;
  /** Closes every toast. */
  clear(): void;
}

const QUEUES = new WeakMap<KitToastQueue, ToastQueue<KitToast>>();

/** The shortest time a toast with a timeout stays, so it can be read (WCAG 2.2, 2.2.1). */
const LEAST_TIMEOUT = 5000;

/**
 * A new, empty queue of toasts, which a page creates once and gives its ToastRegion.
 *
 * @experimental
 */
export function createToastQueue(): KitToastQueue {
  const queue = new ToastQueue<KitToast>({ maxVisibleToasts: 5 });
  const kit: KitToastQueue = {
    add: (toast, options) =>
      queue.add(
        toast,
        options?.timeout === undefined ? {} : { timeout: Math.max(LEAST_TIMEOUT, options.timeout) },
      ),
    close: (key) => {
      queue.close(key);
    },
    clear: () => {
      queue.clear();
    },
  };
  QUEUES.set(kit, queue);

  return kit;
}

/**
 * The props of ToastRegion.
 *
 * @experimental
 */
export interface ToastRegionProps {
  /** The queue whose toasts the region shows. */
  readonly queue: KitToastQueue;
}

const ICONS: Readonly<Record<CalloutTone, IconName>> = {
  info: 'info',
  success: 'success',
  warning: 'warning',
  danger: 'error',
};

/**
 * Where a page's toasts appear: short messages about something that happened, such as a change
 * that was saved, at the end of the screen. The region is a landmark a screen reader announces each
 * toast in; F6 moves focus into it and back, and each toast has a close button named in the kit's
 * own text. A toast with a timeout waits while the pointer or focus is on the region. A page renders
 * one region, once, with the queue it adds its toasts to.
 *
 * @experimental
 */
export function ToastRegion({ queue }: ToastRegionProps) {
  const t = useKitTranslation();
  const ariaQueue = QUEUES.get(queue);

  if (ariaQueue === undefined) {
    throw new Error('ToastRegion takes a queue made by createToastQueue().');
  }

  return (
    <AriaToastRegion queue={ariaQueue} className="cms-toast-region">
      {({ toast }) => {
        const tone = toast.content.tone ?? 'info';

        return (
          <AriaToast toast={toast} className={`cms-toast cms-toast--${tone}`}>
            <span className="cms-toast__icon">
              <Icon name={ICONS[tone]} />
            </span>
            <AriaToastContent className="cms-toast__content">
              <Text slot="title" className="cms-toast__title">
                {toast.content.title}
              </Text>
              {toast.content.description === undefined ? null : (
                <Text slot="description" className="cms-toast__description">
                  {toast.content.description}
                </Text>
              )}
            </AriaToastContent>
            <Button slot="close" className="cms-toast__close" aria-label={t('kit.close')}>
              <Icon name="close" />
            </Button>
          </AriaToast>
        );
      }}
    </AriaToastRegion>
  );
}
