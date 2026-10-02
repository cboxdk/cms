<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Arch;

use Cbox\Cms\Panel\Assets\AssetController;
use Cbox\Cms\Panel\Middleware\SendContentSecurityPolicy;
use Cbox\Cms\Panel\Pages\NotFoundController;
use Cbox\Cms\Panel\PanelRoutes;
use Cbox\Cms\Panel\PanelServiceProvider;
use Cbox\Cms\Tests\Support\Arch\Category;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\DeclaredType;
use Cbox\Cms\Tests\Support\Arch\Layer;
use Cbox\Cms\Tests\Support\Arch\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ReflectionClass;
use ReflectionMethod;

/*
 * The panel surface (GUARDRAILS 2.1, 2.5, PRD 13.4): the panel module, Cbox\Cms\Panel, is a surface
 * in the layers of GUARDRAILS 2.5, as the http, cli and mcp modules are, so the layer rules hold it
 * to actions, DTOs and Boundary and keep Infrastructure, Adapter and Eloquent out of it
 * (LayersTest), and it is a module of the package that no other module uses (ModulesTest). Its
 * controllers hold no logic: a class of the panel whose name ends in Controller uses only actions,
 * DTOs, Boundary classes, the contracts' stability attributes and the framework's request and
 * responses, and is a final readonly class whose only methods are its constructor and __invoke().
 */

const PANEL_NAMESPACE = 'Cbox\Cms\Panel';

/**
 * @return list<DeclaredType>
 */
function panelControllerTypes(): array
{
    return array_values(array_filter(
        Codebase::types(),
        static fn (DeclaredType $type): bool => str_starts_with($type->namespace.'\\', PANEL_NAMESPACE.'\\') && str_ends_with($type->name, 'Controller'),
    ));
}

arch('panel surface: the panel module is a surface of the layers', function (): void {
    expect(Layer::surfaces())->toContain(Layer::Panel)
        ->and(Layer::of(PanelRoutes::class))->toBe(Layer::Panel)
        ->and(Layer::of(PanelServiceProvider::class))->toBe(Layer::Panel)
        ->and(Layer::of(SendContentSecurityPolicy::class))->toBe(Layer::Panel)
        ->and(Codebase::classesIn(Layer::Panel))->toContain(PanelRoutes::class, NotFoundController::class, AssetController::class);
});

arch('panel surface: the panel\'s controllers use only actions, DTOs, Boundary and the framework\'s request and responses', function (): void {
    $controllers = array_map(static fn (DeclaredType $type): string => $type->fqcn(), panelControllerTypes());

    expect($controllers)->toContain(NotFoundController::class, AssetController::class);

    foreach ($controllers as $controller) {
        expect($controller)->toOnlyUse([
            ...Codebase::classesIn(Layer::Actions),
            ...Codebase::classesIn(Layer::Boundary),
            ...Codebase::classesInCategory(Category::Dto),
            'Cbox\Cms\Contracts\Attributes',
            Request::class,
            Response::class,
            JsonResponse::class,
        ]);
    }
});

arch('panel surface: a panel controller is a final readonly class with no method but its constructor and __invoke()', function (): void {
    $violations = [];

    foreach (panelControllerTypes() as $type) {
        $class = new ReflectionClass($type->fqcn());
        $methods = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $class->getMethods());
        sort($methods);

        if (! $class->isFinal() || ! $class->isReadOnly()) {
            $violations[] = sprintf('%s (%s) is not a final readonly class.', $type->fqcn(), Codebase::relative($type->path));
        }

        if ($methods !== ['__construct', '__invoke']) {
            $violations[] = sprintf('%s (%s) has the methods %s; a panel controller has only __construct and __invoke, and its logic lives in a Boundary or an action.', $type->fqcn(), Codebase::relative($type->path), implode(', ', $methods));
        }
    }

    Rules::none($violations, 'The panel\'s controllers hold no logic (GUARDRAILS 2.1):');
});
