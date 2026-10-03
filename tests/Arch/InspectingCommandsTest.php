<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Arch;

use Cbox\Cms\Cli\Console\ActionsCommand;
use Cbox\Cms\Cli\Console\ExplainCommand;
use Cbox\Cms\Cli\Console\HooksCommand;
use Cbox\Cms\Cli\Console\PanelFillsCommand;
use Cbox\Cms\Cli\Console\PanelPointsCommand;
use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\UnknownCommand;
use Cbox\Cms\Core\Registry\Domain\UnknownPanelPoint;
use Cbox\Cms\Tests\Support\Arch\Category;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\Layer;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * The inspecting commands hold no logic (GUARDRAILS 5 and 7.1): cms:actions, cms:hooks,
 * cms:explain, cms:panel:points and cms:panel:fills each call an action, and read their input and print its result through the
 * Boundary. They use only actions, DTOs, Boundary classes, the refusals they turn into exit codes,
 * the contracts' attributes and the framework's console command and output, so cms:explain in
 * particular has no explain code of its own; each declares no method but handle().
 */

arch('inspecting commands use only actions, DTOs, Boundary and the framework\'s console', function (string $command): void {
    expect($command)->toOnlyUse([
        ...Codebase::classesIn(Layer::Actions),
        ...Codebase::classesIn(Layer::Boundary),
        ...Codebase::classesInCategory(Category::Dto),
        CliCallRefused::class,
        UnknownCommand::class,
        UnknownPanelPoint::class,
        RegistryCacheMissing::class,
        MalformedRegistryCache::class,
        'Cbox\Cms\Contracts\Attributes',
        'Illuminate\Console',
        OutputInterface::class,
    ]);
})->with([ActionsCommand::class, HooksCommand::class, ExplainCommand::class, PanelPointsCommand::class, PanelFillsCommand::class]);

arch('inspecting commands declare no method but handle()', function (string $command): void {
    /** @var class-string $command */
    $class = new ReflectionClass($command);
    $declared = array_values(array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        array_filter($class->getMethods(), static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $command),
    ));

    expect($class->isFinal())->toBeTrue()
        ->and($declared)->toBe(['handle']);
})->with([ActionsCommand::class, HooksCommand::class, ExplainCommand::class, PanelPointsCommand::class, PanelFillsCommand::class]);
