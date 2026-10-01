<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use UnexpectedValueException;

/**
 * Reads the rows of a grant as Postgres returns them (PRD 5.10): the columns role_id,
 * classification_ceiling (the role's), path (the node's ltree), effect and locales (a text array,
 * or null for every locale), into a Grant.
 */
#[Internal]
final readonly class GrantRows
{
    /**
     * The columns a grant row is read from, as a query selects them.
     *
     * @var list<string>
     */
    public const array COLUMNS = ['g.role_id', 'r.classification_ceiling', 'n.path', 'g.effect', 'g.locales'];

    /**
     * @throws UnexpectedValueException when the row is not a grant row
     */
    public static function grant(mixed $row): Grant
    {
        if (! is_object($row)) {
            throw new UnexpectedValueException('A grant row is an object.');
        }

        $locales = self::text($row, 'locales', nullable: true);

        return new Grant(
            RoleId::fromString(self::text($row, 'role_id')),
            ClassificationAccess::from(self::text($row, 'classification_ceiling')),
            new NodePath(self::text($row, 'path')),
            GrantEffect::from(self::text($row, 'effect')),
            $locales === null ? null : array_map(static fn (string $locale): Locale => new Locale($locale), self::textArray($locales)),
        );
    }

    /**
     * @return ($nullable is true ? string|null : string)
     */
    private static function text(object $row, string $column, bool $nullable = false): ?string
    {
        $value = property_exists($row, $column) ? $row->{$column} : throw new UnexpectedValueException(sprintf('A grant row has the column %s.', $column));

        if ($value === null && $nullable) {
            return null;
        }

        return is_string($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s of a grant row is text.', $column));
    }

    /**
     * The elements of a text[] literal as Postgres writes it. Locales hold no character it quotes.
     *
     * @return list<string>
     */
    private static function textArray(string $literal): array
    {
        if (preg_match('/\A\{([A-Za-z0-9-]+(?:,[A-Za-z0-9-]+)*)\}\z/', $literal, $match) !== 1) {
            throw new UnexpectedValueException(sprintf('The locales of a grant are a text array of locales, got "%s".', $literal));
        }

        return explode(',', $match[1]);
    }
}
