<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Arch;

use Cbox\Cms\Cli\Console\RunCommand;
use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Tests\Support\Arch\Category;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\Layer;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * The CLI surface holds no logic (GUARDRAILS 2.1: CLI commands build the envelope and the DTO,
 * call the action and translate the result, and the architecture tests enforce it). cms:run uses
 * only actions, DTOs, Boundary classes, the refusal the Boundary throws, the contracts' stability
 * attributes and the framework's console command and output; reading the call and translating its
 * result live in the Boundary, and its only method besides those it inherits is handle().
 */

arch('cli surface: cms:run uses only actions, DTOs, Boundary and the framework\'s console', function (): void {
    expect(RunCommand::class)->toOnlyUse([
        ...Codebase::classesIn(Layer::Actions),
        ...Codebase::classesIn(Layer::Boundary),
        ...Codebase::classesInCategory(Category::Dto),
        CliCallRefused::class,
        'Cbox\Cms\Contracts\Attributes',
        'Illuminate\Console',
        OutputInterface::class,
    ]);
});

arch('cli surface: cms:run declares no method but handle()', function (): void {
    $declared = array_values(array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            new ReflectionClass(RunCommand::class)->getMethods(),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === RunCommand::class,
        ),
    ));

    expect(new ReflectionClass(RunCommand::class)->isFinal())->toBeTrue()
        ->and($declared)->toBe(['handle']);
});
