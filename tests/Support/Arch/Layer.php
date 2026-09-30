<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

/**
 * The layers of GUARDRAILS 2.5, named by the namespace segment that marks them.
 *
 * A namespace is in a layer when one of its segments is the layer name, either as the last
 * segment or with more segments below it. When several segments match, the innermost one
 * decides: Cbox\Cms\Http\Boundary\RequestParser is Boundary, not Http. The contracts package
 * is part of the domain (GUARDRAILS 2.5 lists contracts in the domain row), so a namespace
 * under Cbox\Cms\Contracts without a layer segment is Domain.
 */
enum Layer: string
{
    case Domain = 'Domain';
    case Actions = 'Actions';
    case Boundary = 'Boundary';
    case Adapter = 'Adapter';
    case Infrastructure = 'Infrastructure';
    case Jobs = 'Jobs';
    case Http = 'Http';
    case Cli = 'Cli';
    case Mcp = 'Mcp';

    public const string CONTRACTS = 'Cbox\Cms\Contracts';

    /**
     * The surfaces of GUARDRAILS 2.5: the http, cli and mcp modules, and queue jobs.
     *
     * @return list<self>
     */
    public static function surfaces(): array
    {
        return [self::Http, self::Cli, self::Mcp, self::Jobs];
    }

    public static function of(string $namespace): ?self
    {
        foreach (array_reverse(explode('\\', $namespace)) as $segment) {
            $layer = self::tryFrom($segment);

            if ($layer !== null) {
                return $layer;
            }
        }

        return self::inContracts($namespace) ? self::Domain : null;
    }

    public static function inContracts(string $namespace): bool
    {
        return $namespace === self::CONTRACTS || str_starts_with($namespace, self::CONTRACTS.'\\');
    }

    /**
     * Every layer segment in the namespace, outermost first.
     *
     * @return list<self>
     */
    public static function segmentsOf(string $namespace): array
    {
        $layers = [];

        foreach (explode('\\', $namespace) as $segment) {
            $layer = self::tryFrom($segment);

            if ($layer !== null) {
                $layers[] = $layer;
            }
        }

        return $layers;
    }
}
