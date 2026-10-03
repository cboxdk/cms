<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Schema\TypeName;

/**
 * Where a contribution to a panel point applies. Scope is data: the server evaluates it once,
 * before it encodes any props, and never inside a fill. Every list that is not empty narrows the
 * contribution to what it names, and an empty list does not narrow:
 *
 * - `pages`: the pages it shows on;
 * - `commands`: the command forms it applies to, each a command and version;
 * - `types`: the content types it applies to, by `<owner>:<handle>` as the schema names them, so
 *   the kernel stays type-agnostic;
 * - `fieldTypes`: the field types it applies to, a core type such as `text` or an addon's
 *   `<namespace>:<handle>`;
 * - `requires`: the command or query whose permission the viewer must hold, or null.
 *
 * Each list holds a value once and is kept sorted, so two scopes that say the same are equal.
 */
#[Experimental]
final readonly class Scope
{
    public const string FIELD_TYPE_PATTERN = '/\A(?:[a-z][a-z0-9]{0,19}:)?[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/';

    /** @var list<PageName> */
    public array $pages;

    /** @var list<CommandRef> */
    public array $commands;

    /** @var list<TypeName> */
    public array $types;

    /** @var list<string> */
    public array $fieldTypes;

    /**
     * @param  list<PageName>  $pages
     * @param  list<CommandRef>  $commands
     * @param  list<TypeName>  $types
     * @param  list<string>  $fieldTypes
     *
     * @throws InvalidPanelPoint for a value given twice or a field type that is not a field type's name
     */
    public function __construct(
        array $pages = [],
        array $commands = [],
        array $types = [],
        array $fieldTypes = [],
        public ?CommandName $requires = null,
    ) {
        foreach ($fieldTypes as $fieldType) {
            if (preg_match(self::FIELD_TYPE_PATTERN, $fieldType) !== 1) {
                throw InvalidPanelPoint::because(sprintf('The scope names the field type "%s", which is not a core field type such as "text" or an addon\'s "<namespace>:<handle>".', $fieldType));
            }
        }

        $this->pages = $this->sorted('page', $pages, static fn (PageName $page): string => $page->value);
        $this->commands = $this->sorted('command', $commands, static fn (CommandRef $command): string => $command->toString());
        $this->types = $this->sorted('type', $types, static fn (TypeName $type): string => $type->value);
        $this->fieldTypes = $this->sorted('field type', $fieldTypes, static fn (string $fieldType): string => $fieldType);
    }

    /**
     * The scope that does not narrow: every page, command form, type and field type, for every
     * viewer.
     */
    public static function everywhere(): self
    {
        return new self;
    }

    /**
     * Whether the scope narrows the contribution at all.
     */
    public function narrows(): bool
    {
        return $this->pages !== [] || $this->commands !== [] || $this->types !== [] || $this->fieldTypes !== [] || $this->requires instanceof CommandName;
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @param  callable(T): string  $key
     * @return list<T>
     *
     * @throws InvalidPanelPoint
     */
    private function sorted(string $what, array $values, callable $key): array
    {
        $byKey = [];

        foreach ($values as $value) {
            $text = $key($value);

            if (array_key_exists($text, $byKey)) {
                throw InvalidPanelPoint::because(sprintf('The scope names the %s "%s" twice. Name each once.', $what, $text));
            }

            $byKey[$text] = $value;
        }

        ksort($byKey, SORT_STRING);

        return array_values($byKey);
    }
}
