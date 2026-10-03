// @vitest-environment jsdom

import { screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, test, vi } from 'vitest';

import { TextInput } from '../../src/components/TextInput';
import { Wizard } from '../../src/components/Wizard';
import { active, renderKit } from './harness';

/** The text of the step the list marks as the one shown. */
function currentStep(): string | null {
  const steps = screen.getAllByRole('listitem');

  return steps.find((step) => step.getAttribute('aria-current') === 'step')?.textContent ?? null;
}

/** The texts the test renders, as a caller's translations would give them. */
const TEXT = {
  label: 'Invite a member of staff',
  finish: 'Invite',
  person: 'Person',
  access: 'Access',
  check: 'Check',
  name: 'Name',
  role: 'Role',
  ready: 'Ready.',
};

function Harness({
  onFinish,
  validateStep,
}: {
  readonly onFinish: () => void;
  readonly validateStep?: (id: string) => boolean;
}) {
  const [step, setStep] = useState('person');

  return (
    <Wizard
      label={TEXT.label}
      step={step}
      onStepChange={setStep}
      validateStep={validateStep}
      onFinish={onFinish}
      finishLabel={TEXT.finish}
      steps={[
        { id: 'person', title: TEXT.person, content: <TextInput label={TEXT.name} /> },
        { id: 'access', title: TEXT.access, content: <TextInput label={TEXT.role} /> },
        { id: 'check', title: TEXT.check, content: <p>{TEXT.ready}</p> },
      ]}
    />
  );
}

describe('the keyboard contract of Wizard', () => {
  test('marks the step shown, and Back is disabled on the first', () => {
    renderKit(<Harness onFinish={() => undefined} />);

    expect(currentStep()).toBe('1Person');
    expect(screen.getByRole('button', { name: 'Back' }).hasAttribute('disabled')).toBe(true);
  });

  test('Next moves to the next step and focus to its heading, which says where it is', async () => {
    const { user } = renderKit(<Harness onFinish={() => undefined} />);

    screen.getByRole('button', { name: 'Next' }).focus();
    await user.keyboard('{Enter}');

    const heading = active();
    expect(heading.tagName).toBe('H2');
    expect(heading.textContent).toBe('Step 2 of 3Access');
  });

  test('Back returns to the step before, with focus on its heading', async () => {
    const { user } = renderKit(<Harness onFinish={() => undefined} />);

    screen.getByRole('button', { name: 'Next' }).focus();
    await user.keyboard('{Enter}');
    screen.getByRole('button', { name: 'Back' }).focus();
    await user.keyboard('{Enter}');

    expect(active().textContent).toBe('Step 1 of 3Person');
  });

  test('a step that does not validate keeps the reader on it', async () => {
    const validateStep = vi.fn(() => false);
    const { user } = renderKit(<Harness onFinish={() => undefined} validateStep={validateStep} />);
    const next = screen.getByRole('button', { name: 'Next' });

    next.focus();
    await user.keyboard('{Enter}');

    expect(validateStep).toHaveBeenCalledExactlyOnceWith('person');
    expect(currentStep()).toBe('1Person');
    expect(document.activeElement).toBe(next);
  });

  test('the last step’s button finishes the flow', async () => {
    const onFinish = vi.fn();
    const { user } = renderKit(<Harness onFinish={onFinish} />);

    for (const step of ['person', 'access']) {
      expect(currentStep()?.toLowerCase()).toContain(step);
      screen.getByRole('button', { name: 'Next' }).focus();
      await user.keyboard('{Enter}');
    }

    screen.getByRole('button', { name: 'Invite' }).focus();
    await user.keyboard('{Enter}');

    expect(onFinish).toHaveBeenCalledOnce();
  });
});
