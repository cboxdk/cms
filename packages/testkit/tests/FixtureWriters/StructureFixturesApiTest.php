<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\FixtureWriters;

use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/*
 * The public API of the structure fixtures is pinned (B2, gap report G30): every test of the
 * kernel, the panel, the surfaces and the scale tool builds its tree with site(), node(), mount()
 * and route(), so the node commands may change how the fixtures write a row but never how they are
 * called. A change here is a change every one of those callers has to make, so it is a decision,
 * not a detail.
 */

/**
 * The signature of a method as "<name>(<type> $<name>[ = <default>], ...): <return>".
 */
function structureSignature(string $method): string
{
    $reflected = new ReflectionMethod(PostgresStructureFixtures::class, $method);
    $parameters = array_map(static function (ReflectionParameter $parameter): string {
        $type = $parameter->getType();
        $default = $parameter->isDefaultValueAvailable() ? ' = '.json_encode($parameter->getDefaultValue()) : '';

        return sprintf('%s $%s%s', $type instanceof ReflectionNamedType ? $type->getName() : 'mixed', $parameter->getName(), $default);
    }, $reflected->getParameters());
    $return = $reflected->getReturnType();

    return sprintf('%s(%s): %s', $method, implode(', ', $parameters), $return instanceof ReflectionNamedType ? $return->getName() : 'mixed');
}

it('keeps the signatures its callers build their trees with', function (): void {
    expect(structureSignature('__construct'))->toBe('__construct(Illuminate\Database\ConnectionResolverInterface $connections, Cbox\Cms\Contracts\Clock $clock, Cbox\Cms\Contracts\IdGenerator $ids, string $ownerConnection = "pgsql_owner"): mixed')
        ->and(structureSignature('site'))->toBe('site(string $handle, array $locales): Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureSite')
        ->and(structureSignature('node'))->toBe('node(Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode $parent, string $kind = "section"): Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode')
        ->and(structureSignature('mount'))->toBe('mount(Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode $parent, Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode $source): Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode')
        ->and(structureSignature('route'))->toBe('route(Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureSite $site, Cbox\Cms\Contracts\Content\Locale $locale, string $route, Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode $node): void');
});

it('keeps the kinds of node it writes and the table names it writes them to', function (): void {
    expect(PostgresStructureFixtures::KINDS)->toBe(['site', 'section', 'page', 'list', 'storage'])
        ->and([PostgresStructureFixtures::NODES, PostgresStructureFixtures::SITES, PostgresStructureFixtures::SITE_LOCALES, PostgresStructureFixtures::ROUTES])
        ->toBe(['nodes', 'sites', 'site_locales', 'node_routes'])
        ->and(array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_values(array_filter(
                new ReflectionClass(PostgresStructureFixtures::class)->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === PostgresStructureFixtures::class,
            )),
        ))->toBe(['__construct', 'site', 'node', 'mount', 'route']);
});
