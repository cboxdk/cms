---
title: Components
weight: 41
description: "Every component of the kit, by group, with what it is for and a link to its stories, and the rules every component follows."
---

# Components

The components of the kit, `js/ui-kit`, by group, the groups of the kit's Storybook. Each one is experimental in block B1, and each name links to its stories, which show it in each of its states, in the light and the dark theme, in forced colours and in Danish; beside them its MDX page has its keyboard contract and its props. `npm run storybook` serves them.

## Foundations

The parts every component builds on, under Foundations in the Storybook.

- [`Icon`](../../js/ui-kit/stories/Icon.stories.tsx): the kit's icons, which are decoration only.
- [`KitI18nProvider`](../../js/ui-kit/stories/KitI18nProvider.stories.tsx): gives the kit its locale, `da` or `en`, for its own texts and for React Aria's dates and numbers.
- [`KitRouterProvider`](../../js/ui-kit/stories/KitRouterProvider.stories.tsx): lets the kit's links go through the application's router.

## Layout

- [`AppShell`](../../js/ui-kit/stories/AppShell.stories.tsx): the frame of every page, with the top bar, the navigation and the main landmark, and a skip link. The bar wraps: where the brand and the actions do not fit on one line, as on a phone, the actions go to a line of their own instead of widening the page, and a control in the bar whose text a narrow bar has no room for marks it `cms-app-shell__label`, which leaves the text to a screen reader below 48rem of shell ([Width](foundations.md#width)).
- [`Page`](../../js/ui-kit/stories/Page.stories.tsx) and [`PageHeader`](../../js/ui-kit/stories/PageHeader.stories.tsx): the content of a page and its top, with the page's one heading.
- [`Card`](../../js/ui-kit/stories/Card.stories.tsx) and [`Section`](../../js/ui-kit/stories/Section.stories.tsx): a panel on the raised surface, and a part of a page with a heading.
- [`Stack`](../../js/ui-kit/stories/Stack.stories.tsx) and [`Inline`](../../js/ui-kit/stories/Inline.stories.tsx): children one below the other, and side by side, with the kit's spacing.
- [`Brand`](../../js/ui-kit/stories/Brand.stories.tsx) and [`ShellHeader`](../../js/ui-kit/stories/ShellHeader.stories.tsx): the installation's logo and product name, and the shell's header with the brand at the start and its actions at the end.

## Navigation

- [`SideNav`](../../js/ui-kit/stories/SideNav.stories.tsx): the panel's navigation, with the current page marked.
- [`Breadcrumbs`](../../js/ui-kit/stories/Breadcrumbs.stories.tsx): where a page sits.
- [`Tabs`](../../js/ui-kit/stories/Tabs.stories.tsx): views of one thing, one shown at a time.
- [`Pagination`](../../js/ui-kit/stories/Pagination.stories.tsx): the previous and next buttons of a list read in keyset pages.
- [`TextLink`](../../js/ui-kit/stories/TextLink.stories.tsx) and [`SkipLink`](../../js/ui-kit/stories/SkipLink.stories.tsx): a link, and the first link of a page, to its content.

## Actions

- [`Button`](../../js/ui-kit/stories/Button.stories.tsx) and [`IconButton`](../../js/ui-kit/stories/IconButton.stories.tsx): a button with text, and one with an icon and a required label.
- [`ActionBar`](../../js/ui-kit/stories/ActionBar.stories.tsx): the actions of a page in a toolbar, with an overflow menu.
- [`Menu`](../../js/ui-kit/stories/Menu.stories.tsx): a button that opens a list of actions.
- [`KeyboardShortcut`](../../js/ui-kit/stories/KeyboardShortcut.stories.tsx): the keys of a shortcut, written for the reader's system.

## Forms

- [`Form`](../../js/ui-kit/stories/Form.stories.tsx), [`FormActions`](../../js/ui-kit/stories/FormActions.stories.tsx) and [`Fieldset`](../../js/ui-kit/stories/Fieldset.stories.tsx): a form, its row of buttons, and a group of fields.
- [`Field`](../../js/ui-kit/stories/Field.stories.tsx) and [`FieldError`](../../js/ui-kit/stories/FieldError.stories.tsx): the frame of a field and the error of a control the kit has no component for.
- [`TextInput`](../../js/ui-kit/stories/TextInput.stories.tsx), [`PasswordInput`](../../js/ui-kit/stories/PasswordInput.stories.tsx), [`TextArea`](../../js/ui-kit/stories/TextArea.stories.tsx) and [`NumberInput`](../../js/ui-kit/stories/NumberInput.stories.tsx): text, a password with a reveal button, text of several lines, and a number in the page's locale.
- [`Checkbox`](../../js/ui-kit/stories/Checkbox.stories.tsx), [`Switch`](../../js/ui-kit/stories/Switch.stories.tsx) and [`RadioGroup`](../../js/ui-kit/stories/RadioGroup.stories.tsx): a yes or no, a setting that takes effect at once, and a choice of one of a few.
- [`Select`](../../js/ui-kit/stories/Select.stories.tsx), [`Combobox`](../../js/ui-kit/stories/Combobox.stories.tsx) and [`MultiSelect`](../../js/ui-kit/stories/MultiSelect.stories.tsx): a choice from a list, a choice found by typing, and a choice of several.
- [`ErrorSummary`](../../js/ui-kit/stories/ErrorSummary.stories.tsx): the errors of a refused form, each a link to its field.
- [`JsonEditor`](../../js/ui-kit/stories/JsonEditor.stories.tsx): a JSON value, checked as it is typed.
- [`SchemaForm`](../../js/ui-kit/stories/SchemaForm.stories.tsx) with `readCommandSchema`: a form rendered from a command's JSON Schema, a field per member of the command's document, controlled by the caller, which validates the document and gives the errors back by path ([command form](../addons/panel/command-form.md)).

## Feedback

- [`Callout`](../../js/ui-kit/stories/Callout.stories.tsx): a message about the page's state, in a tone.
- [`ToastRegion`](../../js/ui-kit/stories/ToastRegion.stories.tsx) with `createToastQueue`: short messages about something that happened.
- [`Badge`](../../js/ui-kit/stories/Badge.stories.tsx): a short label of a state.
- [`ProgressLabel`](../../js/ui-kit/stories/ProgressLabel.stories.tsx) and [`Skeleton`](../../js/ui-kit/stories/Skeleton.stories.tsx): a wait that says what it waits for, and the shape of what loads.
- [`EmptyState`](../../js/ui-kit/stories/EmptyState.stories.tsx) and [`ErrorState`](../../js/ui-kit/stories/ErrorState.stories.tsx): what a list or a page shows when it is empty, and when it failed.
- [`StatusScreen`](../../js/ui-kit/stories/StatusScreen.stories.tsx) and [`TaskScreen`](../../js/ui-kit/stories/TaskScreen.stories.tsx): a page about the panel's state, and a page for one task such as signing in.

## Overlays

- [`Dialog`](../../js/ui-kit/stories/Dialog.stories.tsx), [`ConfirmDialog`](../../js/ui-kit/stories/ConfirmDialog.stories.tsx) and [`Drawer`](../../js/ui-kit/stories/Drawer.stories.tsx): a modal dialog, a question before an action that cannot simply be undone, and a modal panel at the end of the screen.
- [`Tooltip`](../../js/ui-kit/stories/Tooltip.stories.tsx): a short hint about a control.
- [`CommandPalette`](../../js/ui-kit/stories/CommandPalette.stories.tsx): the pages and commands found by typing, opened with Ctrl+K or Command+K.
- [`Wizard`](../../js/ui-kit/stories/Wizard.stories.tsx): a flow of steps.

## Data display

- [`DataTable`](../../js/ui-kit/stories/DataTable.stories.tsx): a table of rows with sorting, row actions and keyset pages. A table wider than the room it has scrolls in its own region, which never widens the page ([Width](foundations.md#width)).
- [`DescriptionList`](../../js/ui-kit/stories/DescriptionList.stories.tsx): facts about one thing.
- [`Tree`](../../js/ui-kit/stories/Tree.stories.tsx): a tree of nodes.
- [`Tag`](../../js/ui-kit/stories/Tag.stories.tsx) and [`Timestamp`](../../js/ui-kit/stories/Timestamp.stories.tsx): a short value as a chip, and a point in time in the reader's locale.

## Domain

- [`ReceiptStatus`](../../js/ui-kit/stories/ReceiptStatus.stories.tsx): what a command's receipt says.
- [`DryRunReport`](../../js/ui-kit/stories/DryRunReport.stories.tsx): what a dry run found.
- [`ProblemDetails`](../../js/ui-kit/stories/ProblemDetails.stories.tsx): a refusal from the kernel, with its catalog code.
- [`ActorChip`](../../js/ui-kit/stories/ActorChip.stories.tsx), [`NodePath`](../../js/ui-kit/stories/NodePath.stories.tsx) and [`ClassificationBadge`](../../js/ui-kit/stories/ClassificationBadge.stories.tsx): an actor, where a node sits, and a classification.
- [`NodePicker`](../../js/ui-kit/stories/NodePicker.stories.tsx), [`ActorPicker`](../../js/ui-kit/stories/ActorPicker.stories.tsx) and [`RolePicker`](../../js/ui-kit/stories/RolePicker.stories.tsx): fields to choose a node, an actor and a role.

## Rules for a component

- It takes no `className` or `style` prop. Its appearance comes from props such as `variant` and `tone`, and from the tokens.
- It reads only semantic and component tokens, never a primitive `--cms-ref-*` one.
- Its markup, class names and attributes are internal. The only exception is a part hook, a `data-cms-part` attribute from the curated list in `tokens.json`.
- Its texts come from the caller's translations or from the kit's catalogues, never from literal text.
- It carries a stability tag, and its TSDoc says what it is for and its keyboard contract.
- A component that shows data has an empty, a loading and a failed state, each with a story: what it waits for is said in a `ProgressLabel`, and a failure and an empty list say what to do next.
- It has a story: a file in `js/ui-kit/stories` whose default export names it as its `component`, with a story for each of its states and a play function for its keyboard contract, and the stories `Dark`, `ForcedColors` and `Danish`. Gate 7 fails on a component the kit exports without one, and compares each story with its visual baseline.
- It has an MDX page beside its stories, `js/ui-kit/stories/<Name>.mdx`: its stability and since, its description from its TSDoc, do and don't, and its props.

## Storybook

The kit's Storybook shows every component in its stories, in the locale and the theme of the toolbar. `npm run storybook` serves it on port 6006. Each story is also a test of gate 7: it renders, its play function runs, axe finds no violation, and a screenshot matches the story's baseline in `js/ui-kit/visual-baselines`, rendered in the dev image. Run them with `composer image:run -- npm run storybook:test`, and after a change meant to change how the kit looks, write the baselines again with `composer image:run -- npm run storybook:baselines`. [Gates and CI](../developers/gates-and-ci.md#storybook-and-gate-7) has the details.
