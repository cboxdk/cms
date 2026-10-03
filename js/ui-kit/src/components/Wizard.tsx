import { useEffect, useId, useRef, type ReactNode } from 'react';

import { useKitTranslation } from '../i18n/translations';
import { Button } from './Button';
import { Icon } from './Icon';

import './wizard.css';

/**
 * A step of a Wizard.
 *
 * @experimental
 */
export interface WizardStep {
  /** An id unique among the steps, which onStepChange gives back. */
  readonly id: string;
  /** The step's heading, from the caller's translations. */
  readonly title: string;
  /** The step's fields or text. */
  readonly content: ReactNode;
}

/**
 * The props of Wizard.
 *
 * @experimental
 */
export interface WizardProps {
  /** The flow's name, such as "Invite a member of staff", from the caller's translations. */
  readonly label: string;
  /** The steps, in order. */
  readonly steps: readonly WizardStep[];
  /** The id of the step shown; the wizard is controlled. */
  readonly step: string;
  /** Called with the id of the step to show after Back or Next. */
  readonly onStepChange: (id: string) => void;
  /**
   * Checks the step shown before Next or Finish moves on, such as by validating its fields, and
   * returns false to stay on it; the step then shows what is wrong.
   */
  readonly validateStep?: ((id: string) => boolean) | undefined;
  /** Called by Finish on the last step. */
  readonly onFinish: () => void;
  /** The last step's button, which says what the flow does, such as "Invite". */
  readonly finishLabel: string;
}

/**
 * A flow of steps, one shown at a time, such as inviting a member of staff. The steps are listed
 * above, the step shown marked with aria-current and by more than colour; below it come Back and
 * Next, named in the kit's own text, and on the last step the caller's Finish. Moving to another
 * step moves focus to its heading, which a screen reader then reads with "Step 2 of 3", so the
 * keyboard starts at the top of the new step.
 *
 * @experimental
 */
export function Wizard({
  label,
  steps,
  step,
  onStepChange,
  validateStep,
  onFinish,
  finishLabel,
}: WizardProps) {
  const t = useKitTranslation();
  const id = useId();
  const heading = useRef<HTMLHeadingElement>(null);
  const shown = useRef(step);
  const index = Math.max(
    0,
    steps.findIndex((candidate) => candidate.id === step),
  );
  const current = steps[index];
  const last = index === steps.length - 1;

  useEffect(() => {
    if (shown.current !== step) {
      shown.current = step;
      heading.current?.focus();
    }
  }, [step]);

  if (current === undefined) {
    return null;
  }

  const forward = () => {
    if (validateStep !== undefined && !validateStep(current.id)) {
      return;
    }

    const next = steps[index + 1];

    if (next === undefined) {
      onFinish();
    } else {
      onStepChange(next.id);
    }
  };

  return (
    <section className="cms-wizard" aria-labelledby={`${id}-label`}>
      <p id={`${id}-label`} className="cms-wizard__label">
        {label}
      </p>
      <ol className="cms-wizard__steps">
        {steps.map((candidate, position) => (
          <li
            key={candidate.id}
            className="cms-wizard__step"
            data-state={position < index ? 'done' : position === index ? 'current' : 'ahead'}
            aria-current={position === index ? 'step' : undefined}
          >
            <span className="cms-wizard__marker" aria-hidden="true">
              {position < index ? <Icon name="check" size="sm" /> : String(position + 1)}
            </span>
            <span>{candidate.title}</span>
          </li>
        ))}
      </ol>
      <div className="cms-wizard__panel">
        <h2 ref={heading} tabIndex={-1} className="cms-wizard__title">
          <span className="cms-wizard__count">
            {t('kit.wizard.step', { current: index + 1, total: steps.length })}
          </span>
          {current.title}
        </h2>
        <div className="cms-wizard__content">{current.content}</div>
      </div>
      <div className="cms-wizard__actions">
        <Button
          icon="chevron-left"
          disabled={index === 0}
          onClick={() => {
            const previous = steps[index - 1];

            if (previous !== undefined) {
              onStepChange(previous.id);
            }
          }}
        >
          {t('kit.wizard.back')}
        </Button>
        <Button variant="primary" onClick={forward}>
          {last ? finishLabel : t('kit.wizard.next')}
        </Button>
      </div>
    </section>
  );
}
