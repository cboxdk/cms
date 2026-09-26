<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Selftest\Domain;

/**
 * The violations `composer check:selftest` plants: at least one per gate of the local profile,
 * and one per tool where a gate has two. Each file is otherwise clean for its own gate, so the
 * gate fails because of the plant. The PHP plants live in the module Selftest of the core
 * package, which does not exist in the repository.
 */
final readonly class Plants
{
    public const string MODULE = 'packages/core/src/Selftest';

    /**
     * @return list<Plant>
     */
    public static function all(): array
    {
        $marker = 'TO'.'DO';

        return [
            new Plant(1, 'Pint', 'PHP that Pint would reformat', self::MODULE.'/Domain/Formatting.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Cbox\Cms\Core\Selftest\Domain;

                use Cbox\Cms\Contracts\Attributes\Internal;

                #[Internal]
                final readonly class Formatting
                {
                    public function value( ) : int { return 1 ; }
                }

                PHP, false, []),
            new Plant(1, 'Prettier', 'TypeScript that Prettier would reformat', 'workbench/resources/js/selftest/formatting.ts', <<<'TS'
                export const formatting   =  {a:1}

                TS, false, []),
            new Plant(2, 'Rector', 'an if that returns true or false, which Rector simplifies', self::MODULE.'/Domain/RectorPattern.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Cbox\Cms\Core\Selftest\Domain;

                use Cbox\Cms\Contracts\Attributes\Internal;

                #[Internal]
                final readonly class RectorPattern
                {
                    public function isPositive(int $value): bool
                    {
                        if ($value > 0) {
                            return true;
                        }

                        return false;
                    }
                }

                PHP, false, ['SimplifyIfReturnBoolRector']),
            new Plant(3, 'PHPStan', 'mixed in Domain', self::MODULE.'/Domain/MixedValue.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Cbox\Cms\Core\Selftest\Domain;

                use Cbox\Cms\Contracts\Attributes\Internal;

                #[Internal]
                final readonly class MixedValue
                {
                    public function __construct(public mixed $value) {}
                }

                PHP, false, ['cboxCms.mixed']),
            // The comment hides a real error, so only the testkit's rule can report this file.
            new Plant(3, 'PHPStan', '@phpstan-ignore-next-line in Domain', self::MODULE.'/Domain/IgnoreComment.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Cbox\Cms\Core\Selftest\Domain;

                use Cbox\Cms\Contracts\Attributes\Internal;

                #[Internal]
                final readonly class IgnoreComment
                {
                    public function value(): int
                    {
                        // @phpstan-ignore-next-line
                        return 'one';
                    }
                }

                PHP, false, ['cboxCms.phpstanIgnore']),
            // A comment that tries to ignore the rule that reports it. It is reported only because
            // the rule's errors cannot be ignored.
            new Plant(3, 'PHPStan', '@phpstan-ignore aimed at cboxCms.phpstanIgnore in Domain', self::MODULE.'/Domain/SelfIgnore.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Cbox\Cms\Core\Selftest\Domain;

                use Cbox\Cms\Contracts\Attributes\Internal;

                #[Internal]
                final readonly class SelfIgnore
                {
                    public function value(): int
                    {
                        return 1; // @phpstan-ignore cboxCms.phpstanIgnore
                    }
                }

                PHP, false, ['cboxCms.phpstanIgnore']),
            new Plant(3, 'PHPStan', 'a transaction call in Actions', self::MODULE.'/Actions/TransactionCall.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Cbox\Cms\Core\Selftest\Actions;

                use Cbox\Cms\Contracts\Attributes\Internal;
                use Illuminate\Database\ConnectionInterface;

                #[Internal]
                final readonly class TransactionCall
                {
                    public function __construct(private ConnectionInterface $connection) {}

                    public function handle(): void
                    {
                        $this->connection->beginTransaction();
                    }
                }

                PHP, false, ['cboxCms.transaction']),
            new Plant(4, 'tsc', 'a type error in TypeScript', 'workbench/resources/js/selftest/type-error.ts', <<<'TS'
                export const count: number = 'one';

                TS, false, ['TS2322']),
            new Plant(4, 'ESLint', 'an explicit any in TypeScript', 'workbench/resources/js/selftest/explicit-any.ts', <<<'TS'
                export function parse(value: string): any {
                  return JSON.parse(value);
                }

                TS, false, ['no-explicit-any']),
            new Plant(5, 'Arch', 'Illuminate\Http in Domain, a layer violation', self::MODULE.'/Domain/LayerViolation.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Cbox\Cms\Core\Selftest\Domain;

                use Cbox\Cms\Contracts\Attributes\Internal;
                use Illuminate\Http\Request;

                #[Internal]
                final readonly class LayerViolation
                {
                    public function __construct(public Request $request) {}
                }

                PHP, false, ['Illuminate\Http']),
            // The marker is joined from parts, so this file passes the marker gate itself.
            new Plant(5, 'Arch', 'a marker word of GUARDRAILS 11 in a comment', self::MODULE.'/Domain/MarkerComment.php', <<<PHP
                <?php

                declare(strict_types=1);

                namespace Cbox\\Cms\\Core\\Selftest\\Domain;

                use Cbox\\Cms\\Contracts\\Attributes\\Internal;

                #[Internal]
                final readonly class MarkerComment
                {
                    // {$marker}: return the real value.
                    public function value(): int
                    {
                        return 1;
                    }
                }

                PHP, false, [self::MODULE.'/Domain/MarkerComment.php:12: '.$marker]),
            new Plant(6, 'check:generated', 'a generated file edited by hand', 'workbench/app/Cms/Generated/TypeHandle.php', <<<'PHP'

                // Edited by hand.

                PHP, true, ['not the committed code']),
        ];
    }
}
