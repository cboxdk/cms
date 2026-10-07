<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\CommandForm\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\Ownership;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\ReplacementKey;
use Cbox\Cms\Panel\CommandForm\Domain\CommandForm;
use Cbox\Cms\Panel\CommandForm\Domain\FieldPresence;

/**
 * The props of command.form.field@1, the input of one field of the generic command form (PRD
 * 13.4, section 3.6 of the panel extension architecture): a replacement point keyed by the value
 * class the command binds the member to, such as Cbox\Cms\Contracts\Ids\NodeId, with
 * Ownership::Own, so an addon replaces the inputs of its own value classes alone, and the core
 * ships the pickers of NodeId, ActorId and RoleId as its own contributions. A replacement gets the
 * command's name and version, the member's path in the document, the id of the control (`id` in
 * JSON, `control` here, because it is a DOM id, not an id of the kernel's), the label and the
 * description the default input shows, the member's JSON Schema node, its value as text or null while the
 * field is empty, what is wrong with it, whether the form is read-only while it submits, the
 * panel's locale and the member's presence, and, in the browser, onChange with the next value;
 * one that throws gives the default input back. The props exist only in the browser, per field,
 * so the page holds them.
 */
#[Experimental]
#[PanelPoint(name: CommandForm::FIELD, version: 1, kind: PointKind::Replacement, page: CommandForm::PAGE, since: '1.0', label: 'panel.points.command_form_field', multiplicity: Multiplicity::Exclusive, ownership: Ownership::Own, keyedBy: ReplacementKey::ValueClass)]
final readonly class FieldInputPropsV1
{
    /**
     * @param  list<string>  $errors  what is wrong with the value, each in the panel's locale
     */
    public function __construct(
        public CommandName $command,
        public int $version,
        public string $path,
        public string $control,
        public string $label,
        public ?string $description,
        public JsonDocument $schema,
        public ?string $value,
        public array $errors,
        public bool $readOnly,
        public Locale $locale,
        public FieldPresence $presence,
    ) {}
}
