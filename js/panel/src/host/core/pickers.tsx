// The core's pickers of a command form's fields (PRD 13.4, section 8 of the panel extension
// architecture): the cms replacements at command.form.field@1 of every member a command binds to
// NodeId, ActorId or RoleId, each over the kit's picker with the kernel's list, node.list,
// actor.list or role.list, as its data, read as the viewer on the server. While the list loads
// the picker says so; when the viewer may not read it, or it failed, the field takes the id typed
// by hand, with the hint that says so, so no form is a dead end. Each control keeps the default input's id, so the form's
// error summary still links to it, and its name, so the value is submitted under the member's
// path.

import type { JsonValue } from '@cboxdk/cms-panel/extend';
import type { FieldInputData, FieldInputProps } from '@cboxdk/cms-panel/experimental';
import { usePanelHost } from '@cboxdk/cms-panel/extend';
import { ActorPicker, EmptyState, NodePicker, RolePicker, TextInput } from '@cboxdk/cms-ui-kit';
import { useMemo, useState } from 'react';

import { nodeTree, pickerActors, pickerRoles } from './lists';

type PickerProps = FieldInputProps & { readonly data: FieldInputData<JsonValue> };

/** The id typed by hand, for a list the viewer cannot read. */
function TypedId({ props }: { readonly props: FieldInputProps }) {
  const { t } = usePanelHost();

  return (
    <TextInput
      id={props.id}
      name={props.path}
      label={props.label}
      description={t('panel.pickers.typed', { hint: props.description ?? '' })}
      error={props.errors[0]}
      required={props.presence === 'required'}
      disabled={props.read_only}
      value={props.value ?? ''}
      autoComplete="off"
      spellCheck={false}
      onChange={(event) => {
        props.onChange(event.target.value === '' ? null : event.target.value);
      }}
    />
  );
}

/** The picker of a node, over node.list. */
export default function NodePickerInput(props: PickerProps) {
  const { t } = usePanelHost();
  const nodes = useMemo(
    () => (props.data.status === 'ready' ? nodeTree(props.data.value) : []),
    [props.data],
  );

  if (props.data.status === 'failed') {
    return <TypedId props={props} />;
  }

  return (
    <NodePicker
      label={props.label}
      description={props.description ?? undefined}
      error={props.errors[0]}
      nodes={nodes}
      value={props.value}
      onChange={props.onChange}
      loading={props.data.status === 'loading' ? t('panel.pickers.nodes_loading') : undefined}
      empty={<EmptyState title={t('panel.pickers.nodes_empty')} headingLevel={4} />}
      required={props.presence === 'required'}
    />
  );
}

/** The picker of an actor, over actor.list. */
export function ActorPickerInput(props: PickerProps) {
  const { t } = usePanelHost();
  const [search, setSearch] = useState('');
  const actors = useMemo(
    () => (props.data.status === 'ready' ? pickerActors(props.data.value) : []),
    [props.data],
  );

  if (props.data.status === 'failed') {
    return <TypedId props={props} />;
  }

  return (
    <ActorPicker
      label={props.label}
      description={props.description ?? undefined}
      error={props.errors[0]}
      actors={actors.filter((actor) => matches(actor.name, actor.email, search))}
      value={props.value}
      onChange={props.onChange}
      search={search}
      onSearchChange={setSearch}
      loading={props.data.status === 'loading' ? t('panel.pickers.actors_loading') : undefined}
      emptyLabel={t('panel.pickers.actors_empty')}
      name={props.path}
      required={props.presence === 'required'}
    />
  );
}

/** The picker of a role, over role.list. */
export function RolePickerInput(props: PickerProps) {
  const { t } = usePanelHost();
  const roles = useMemo(
    () => (props.data.status === 'ready' ? pickerRoles(props.data.value) : []),
    [props.data],
  );

  if (props.data.status === 'failed') {
    return <TypedId props={props} />;
  }

  return (
    <RolePicker
      label={props.label}
      description={props.description ?? undefined}
      error={props.errors[0]}
      roles={roles}
      value={props.value}
      onChange={props.onChange}
      loading={props.data.status === 'loading' ? t('panel.pickers.roles_loading') : undefined}
      emptyLabel={t('panel.pickers.roles_empty')}
      name={props.path}
      required={props.presence === 'required'}
    />
  );
}

/** Whether an actor's name or email holds what is typed, ignoring case. */
function matches(name: string, email: string | undefined, search: string): boolean {
  const typed = search.trim().toLocaleLowerCase();

  return (
    typed === '' ||
    name.toLocaleLowerCase().includes(typed) ||
    (email !== undefined && email.toLocaleLowerCase().includes(typed))
  );
}
