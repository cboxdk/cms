import { TextInput, Wizard, type WizardProps } from '@cboxdk/cms-ui-kit';
import { useState } from 'react';

import {
  check,
  focused,
  inDanish,
  inDark,
  inForcedColours,
  textsOf,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<WizardProps> = {
  title: 'Components/Overlays/Wizard',
  component: Wizard,
};

export default meta;

const TEXTS: Localized<{
  label: string;
  person: string;
  access: string;
  check: string;
  name: string;
  email: string;
  role: string;
  summary: string;
  finish: string;
}> = {
  da: {
    label: 'Invitér en medarbejder',
    person: 'Person',
    access: 'Adgang',
    check: 'Kontrollér',
    name: 'Navn',
    email: 'E-mail',
    role: 'Rolle',
    summary: 'Medarbejderen får en invitation på e-mail.',
    finish: 'Invitér',
  },
  en: {
    label: 'Invite a member of staff',
    person: 'Person',
    access: 'Access',
    check: 'Check',
    name: 'Name',
    email: 'Email',
    role: 'Role',
    summary: 'The member of staff gets an invitation by email.',
    finish: 'Invite',
  },
};

function Invite({
  globals,
  start,
}: {
  readonly globals: Readonly<Record<string, unknown>>;
  readonly start: string;
}) {
  const texts = textsOf(TEXTS, globals);
  const [step, setStep] = useState(start);

  return (
    <Wizard
      label={texts.label}
      step={step}
      onStepChange={setStep}
      finishLabel={texts.finish}
      onFinish={() => {
        document.body.dataset['finished'] = 'true';
      }}
      steps={[
        {
          id: 'person',
          title: texts.person,
          content: (
            <>
              <TextInput label={texts.name} defaultValue="Ada Lovelace" />
              <TextInput label={texts.email} type="email" defaultValue="ada@example.com" />
            </>
          ),
        },
        { id: 'access', title: texts.access, content: <TextInput label={texts.role} /> },
        { id: 'check', title: texts.check, content: <p>{texts.summary}</p> },
      ]}
    />
  );
}

/** The first step; Back is disabled. */
export const FirstStep: Story = {
  render: (_args, { globals }) => <Invite globals={globals} start="person" />,
  play: ({ canvasElement }) => {
    const current = canvasElement.querySelector('[aria-current="step"]');
    const [back] = canvasElement.querySelectorAll('.cms-wizard__actions button');

    check(current !== null, 'the step shown is marked');
    check(back instanceof HTMLButtonElement && back.disabled, 'Back is disabled on the first step');
  },
};

/** Next moves to the next step and focus to its heading; Back returns. */
export const Keyboard: Story = {
  render: FirstStep.render,
  play: async ({ canvasElement, globals, userEvent }) => {
    const texts = textsOf(TEXTS, globals);
    const next = () =>
      canvasElement.querySelectorAll<HTMLButtonElement>('.cms-wizard__actions button')[1];

    next()?.focus();
    await userEvent.keyboard('{Enter}');
    check(focused(HTMLHeadingElement).textContent.includes(texts.access), 'focus is on step 2');
    next()?.focus();
    await userEvent.keyboard('{Enter}');
    check(focused(HTMLHeadingElement).textContent.includes(texts.check), 'focus is on step 3');
    check(next()?.textContent === texts.finish, 'the last step has the finishing button');
    next()?.focus();
    await userEvent.keyboard('{Enter}');
    check(document.body.dataset['finished'] === 'true', 'Finish ends the flow');
    delete document.body.dataset['finished'];
  },
};

/** The last step, with the steps before it done. */
export const LastStep: Story = {
  render: (_args, { globals }) => <Invite globals={globals} start="check" />,
};

export const Dark: Story = inDark(LastStep);
export const ForcedColors: Story = inForcedColours(LastStep);
export const Danish: Story = inDanish(FirstStep);
