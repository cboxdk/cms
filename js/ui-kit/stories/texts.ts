// The texts the kit's stories show, in each locale of the kit. The kit's components take their
// texts from the caller's translations (GUARDRAILS 8), so the stories are such a caller: every
// story reads its texts here, in the locale the toolbar sets, and none is written into its markup.

import type { KitLocale } from '@cboxdk/cms-ui-kit';

export interface StoryTexts {
  readonly saved: string;
  readonly refused: string;
  readonly save: string;
  readonly cancel: string;
  readonly delete: string;
  readonly email: string;
  readonly emailHint: string;
  readonly emailError: string;
  readonly password: string;
  readonly signIn: string;
  readonly signInDescription: string;
  readonly forgotPassword: string;
  readonly missingCode: string;
  readonly missingTitle: string;
  readonly missingDescription: string;
  readonly backToPanel: string;
}

export const STORY_TEXTS: Readonly<Record<KitLocale, StoryTexts>> = {
  da: {
    saved: 'Ændringerne er gemt.',
    refused: 'Formularen blev afvist. Ret felterne markeret nedenfor.',
    save: 'Gem',
    cancel: 'Annullér',
    delete: 'Slet',
    email: 'E-mail',
    emailHint: 'Den adresse, du blev inviteret på.',
    emailError: 'Skriv en e-mailadresse.',
    password: 'Adgangskode',
    signIn: 'Log ind',
    signInDescription: 'Log ind for at redigere indhold.',
    forgotPassword: 'Glemt adgangskode?',
    missingCode: '404',
    missingTitle: 'Siden findes ikke',
    missingDescription: 'Adressen peger ikke på en side i panelet.',
    backToPanel: 'Tilbage til panelet',
  },
  en: {
    saved: 'The changes are saved.',
    refused: 'The form was refused. Correct the fields marked below.',
    save: 'Save',
    cancel: 'Cancel',
    delete: 'Delete',
    email: 'Email',
    emailHint: 'The address you were invited at.',
    emailError: 'Enter an email address.',
    password: 'Password',
    signIn: 'Sign in',
    signInDescription: 'Sign in to edit content.',
    forgotPassword: 'Forgot your password?',
    missingCode: '404',
    missingTitle: 'There is no such page',
    missingDescription: 'The address does not lead to a page of the panel.',
    backToPanel: 'Back to the panel',
  },
};
