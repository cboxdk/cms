// The shape of the kit's stories: Storybook's Component Story Format, as far as the stories use it.
// Storybook's own declarations do not compile under the repository's tsconfig
// (exactOptionalPropertyTypes without skipLibCheck), so the stories are typed here instead.
//
// Every story renders from the globals the toolbar sets, the locale and the theme, so it reads its
// texts from stories/texts.ts in the locale it is shown in. A play function gets the rendered
// canvas and Storybook's userEvent, and fails the story's test by throwing: check() below.

import { DEFAULT_KIT_LOCALE, isKitLocale, type KitLocale } from '@cboxdk/cms-ui-kit';
import type { ReactNode } from 'react';

/** The globals of the toolbar: the locale and the theme a story is shown in. */
export type StoryGlobals = Readonly<Record<string, unknown>>;

/** A theme of the kit's tokens, set as data-theme on the document. */
export type StoryTheme = 'light' | 'dark';

/** The part of Storybook's userEvent (Testing Library's user-event) that the play functions use. */
export interface StoryUserEvent {
  click(element: Element): Promise<void>;
  hover(element: Element): Promise<void>;
  clear(element: Element): Promise<void>;
  keyboard(text: string): Promise<void>;
  type(element: Element, text: string): Promise<void>;
  tab(options?: { readonly shift?: boolean }): Promise<void>;
}

/** What Storybook hands a story's render and play functions. */
export interface StoryContext {
  readonly globals: StoryGlobals;
  readonly canvasElement: HTMLElement;
  readonly userEvent: StoryUserEvent;
}

/** One story: what it renders, and the interactions its test performs on it. */
export interface Story {
  readonly name?: string;
  /** The globals the story is shown in, over the toolbar's: its locale and its theme. */
  readonly globals?: StoryGlobals;
  readonly render: (args: Readonly<Record<string, never>>, context: StoryContext) => ReactNode;
  readonly play?: (context: StoryContext) => Promise<void> | void;
}

/**
 * The default export of a story file. component names the kit export the file shows, which the
 * check of gate 7 holds every component the kit exports to (scripts/story-exports.js).
 */
export interface StoryMeta<Props> {
  readonly title: string;
  readonly component: (props: Props) => ReactNode;
  readonly tags?: readonly string[];
}

/** The locale a story is shown in: the toolbar's, or the kit's default. */
export function storyLocale(globals: StoryGlobals): KitLocale {
  const locale = globals['locale'];

  return typeof locale === 'string' && isKitLocale(locale) ? locale : DEFAULT_KIT_LOCALE;
}

/** The theme a story is shown in: the toolbar's, or light. */
export function storyTheme(globals: StoryGlobals): StoryTheme {
  return globals['theme'] === 'dark' ? 'dark' : 'light';
}

/** A text of a story in each locale of the kit; the stories are callers of the kit's components. */
export type Localized<Texts> = Readonly<Record<KitLocale, Texts>>;

/** The texts of a story in the locale it is shown in. */
export function textsOf<Texts>(texts: Localized<Texts>, globals: StoryGlobals): Texts {
  return texts[storyLocale(globals)];
}

/**
 * The story in the dark theme. Every component has one, so its baseline shows the dark tokens.
 */
export function inDark(story: Story): Story {
  return { ...story, globals: { ...story.globals, theme: 'dark' } };
}

/**
 * The story in Danish. Every component has one, so its baseline shows the kit's and the caller's
 * Danish texts, which are often longer than the English ones.
 */
export function inDanish(story: Story): Story {
  return { ...story, globals: { ...story.globals, locale: 'da' } };
}

/**
 * The name Storybook gives the story exported as ForcedColors, which the story tests look for.
 */
export const FORCED_COLOURS = 'Forced Colors';

/**
 * The story in forced colours, as Windows' high contrast themes show a page. Every component has
 * one, exported as ForcedColors: the story tests emulate forced-colors: active for the story named
 * FORCED_COLOURS (.storybook/vitest.setup.ts), so its baseline shows that the component keeps its
 * borders, focus ring and states when the browser replaces its colours. In Storybook itself,
 * emulate it in the browser's developer tools. The globals are the story's own.
 */
export function inForcedColours(story: Story): Story {
  return { ...story, globals: { ...story.globals, colours: 'forced' } };
}

/** Fails the story's test with the message unless the condition holds. */
export function check(condition: boolean, message: string): asserts condition {
  if (!condition) {
    throw new Error(message);
  }
}

/** The one element in the canvas that matches the selector; fails the test otherwise. */
export function single<T extends Element>(
  canvas: HTMLElement,
  selector: string,
  type: abstract new () => T,
): T {
  const found = canvas.querySelectorAll(selector);
  const element = found[0];

  check(found.length === 1, `${selector} matches ${String(found.length)} elements, not one`);
  check(element instanceof type, `${selector} is not a ${type.name}`);

  return element;
}

/**
 * Waits until the condition holds, as after an interaction React renders in a later task, and
 * fails the test with the message when it does not within a second.
 */
export async function waitFor(condition: () => boolean, message: string): Promise<void> {
  const deadline = performance.now() + 1000;

  while (!condition()) {
    if (performance.now() > deadline) {
      throw new Error(message);
    }

    await new Promise((resolve) => {
      setTimeout(resolve, 10);
    });
  }
}

/** The element that has focus, as an element of the type; fails the test otherwise. */
export function focused<T extends Element>(type: abstract new () => T): T {
  const element = document.activeElement;

  check(element instanceof type, `the element in focus is not a ${type.name}`);

  return element;
}
