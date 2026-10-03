import { cloneElement, useRef, type ReactElement, type Ref, type RefObject } from 'react';
import { mergeProps, Overlay, useOverlayPosition, useTooltip, useTooltipTrigger } from 'react-aria';
import { useTooltipTriggerState, type TooltipTriggerState } from 'react-stately';

import { defined } from './internal/defined';

import './tooltip.css';

/**
 * The props of Tooltip.
 *
 * @experimental
 */
export interface TooltipProps {
  /** The tooltip's text, from the caller's translations: a hint, never what only it says. */
  readonly content: string;
  /**
   * The element the tooltip is about, such as an IconButton: a focusable element that takes a ref
   * and props, which the tooltip describes through aria-describedby.
   */
  readonly children: ReactElement<{ ref?: Ref<HTMLElement> }>;
}

/**
 * A short hint shown above an element while the pointer rests on it or it has keyboard focus, such
 * as the label of an icon button. It appears after a short wait under a pointer and at once on
 * focus, stays while the pointer moves onto it, and Escape hides it (WCAG 2.2, 1.4.13). A screen
 * reader reads it as the element's description; it is never the only place a text is given.
 *
 * @experimental
 */
export function Tooltip({ content, children }: TooltipProps) {
  const state = useTooltipTriggerState({ delay: 600, closeDelay: 300 });
  const ref = useRef<HTMLElement>(null);
  const { triggerProps, tooltipProps } = useTooltipTrigger({}, state, ref);

  return (
    <>
      {cloneElement(children, { ...mergeProps(children.props, triggerProps), ref })}
      {state.isOpen ? (
        <Bubble state={state} triggerRef={ref} props={tooltipProps}>
          {content}
        </Bubble>
      ) : null}
    </>
  );
}

function Bubble({
  state,
  triggerRef,
  props,
  children,
}: {
  readonly state: TooltipTriggerState;
  readonly triggerRef: RefObject<HTMLElement | null>;
  readonly props: ReturnType<typeof useTooltipTrigger>['tooltipProps'];
  readonly children: string;
}) {
  const overlayRef = useRef<HTMLDivElement>(null);
  const { overlayProps } = useOverlayPosition({
    targetRef: triggerRef,
    overlayRef,
    placement: 'top',
    offset: 6,
    isOpen: state.isOpen,
  });
  const { tooltipProps } = useTooltip(defined(props), state);

  return (
    <Overlay disableFocusManagement>
      <div {...mergeProps(overlayProps, tooltipProps)} ref={overlayRef} className="cms-tooltip">
        {children}
      </div>
    </Overlay>
  );
}
