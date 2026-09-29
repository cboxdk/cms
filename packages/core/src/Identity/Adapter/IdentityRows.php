<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\IssuedCredential;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use DateTimeImmutable;
use Exception;
use ValueError;

/**
 * Maps the rows of the identity tables to Actor and IssuedCredential (GUARDRAILS 2.2).
 *
 * An actor row has the columns of ACTOR_COLUMNS, read with the aliases the queries give them. A
 * value the domain refuses, such as an unknown state or a malformed id, throws
 * UnreadableIdentityRow naming the table.
 */
#[Internal]
final readonly class IdentityRows
{
    /**
     * An actor's columns with the prefix the queries give them: actor_id, actor_class, actor_state,
     * actor_version and actor_credential_generation.
     */
    public const string PREFIX = 'actor_';

    /**
     * The select list of the columns of `actors` under the alias, with PREFIX on each name.
     *
     * @return list<string>
     */
    public static function actorColumns(string $alias): array
    {
        return [
            $alias.'.id as actor_id',
            $alias.'.actor_class as actor_class',
            $alias.'.state as actor_state',
            $alias.'.version as actor_version',
            $alias.'.credential_generation as actor_credential_generation',
        ];
    }

    public static function actor(mixed $row, string $table): Actor
    {
        $row = self::row($row, $table);

        try {
            return new Actor(
                ActorId::fromString(self::string($row, 'actor_id', $table)),
                ActorClass::from(self::string($row, 'actor_class', $table)),
                ActorState::from(self::string($row, 'actor_state', $table)),
                self::int($row, 'actor_version', $table),
                new CredentialGeneration(self::int($row, 'actor_credential_generation', $table)),
            );
        } catch (InvalidIdentity|InvalidUuid7|ValueError $refused) {
            throw UnreadableIdentityRow::refused($table, $refused);
        }
    }

    /**
     * @param  list<ActorId>  $onBehalfOf
     */
    public static function credential(mixed $row, array $onBehalfOf, string $table): IssuedCredential
    {
        $row = self::row($row, $table);

        try {
            return new IssuedCredential(
                ActorId::fromString(self::string($row, 'actor_id', $table)),
                $onBehalfOf,
                IssuerKind::from(self::string($row, 'issuer_kind', $table)),
                ClassificationAccess::from(self::string($row, 'classification_ceiling', $table)),
                new CredentialGeneration(self::int($row, 'credential_generation', $table)),
                new DateTimeImmutable(self::string($row, 'expires_at', $table)),
            );
        } catch (InvalidIdentity|InvalidUuid7|ValueError|Exception $refused) {
            throw UnreadableIdentityRow::refused($table, $refused);
        }
    }

    public static function string(object $row, string $column, string $table): string
    {
        $value = self::column($row, $column, $table);

        return is_string($value) ? $value : throw UnreadableIdentityRow::wrongType($table, $column, get_debug_type($value));
    }

    private static function int(object $row, string $column, string $table): int
    {
        $value = self::column($row, $column, $table);

        return is_int($value) ? $value : throw UnreadableIdentityRow::wrongType($table, $column, get_debug_type($value));
    }

    private static function column(object $row, string $column, string $table): mixed
    {
        $values = get_object_vars($row);

        return array_key_exists($column, $values) ? $values[$column] : throw UnreadableIdentityRow::missingColumn($table, $column);
    }

    private static function row(mixed $row, string $table): object
    {
        return is_object($row) ? $row : throw UnreadableIdentityRow::notARow($table, get_debug_type($row));
    }
}
