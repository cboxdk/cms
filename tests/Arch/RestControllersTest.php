<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Arch;

use Cbox\Cms\Http\Rest\RestCommandController;
use Cbox\Cms\Http\Rest\RestQueryController;
use Cbox\Cms\Tests\Support\Arch\Category;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\DeclaredType;
use Cbox\Cms\Tests\Support\Arch\Layer;
use Cbox\Cms\Tests\Support\Arch\Rules;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ReflectionClass;
use ReflectionMethod;

/*
 * The REST controllers hold no logic (GUARDRAILS 2.1: controllers build the envelope and the DTO,
 * call the action and translate the result, and the architecture tests enforce it). A controller
 * of the REST surface, a class in Cbox\Cms\Http\Rest whose name ends in Controller, uses only
 * actions, DTOs, Boundary classes, the contracts' stability attributes, and the request and
 * response of the framework; everything it does beyond calling them lives in a Boundary. It is a
 * final readonly class whose only methods are its constructor and __invoke().
 */

const REST_NAMESPACE = 'Cbox\Cms\Http\Rest';

/**
 * @return list<DeclaredType>
 */
function restControllerTypes(): array
{
    return array_values(array_filter(
        Codebase::types(),
        static fn (DeclaredType $type): bool => $type->namespace === REST_NAMESPACE && str_ends_with($type->name, 'Controller'),
    ));
}

arch('rest: the REST controllers use only actions, DTOs, Boundary and the framework\'s request and response', function (): void {
    $controllers = array_map(static fn (DeclaredType $type): string => $type->fqcn(), restControllerTypes());

    expect($controllers)->toContain(RestCommandController::class, RestQueryController::class);

    foreach ($controllers as $controller) {
        expect($controller)->toOnlyUse([
            ...Codebase::classesIn(Layer::Actions),
            ...Codebase::classesIn(Layer::Boundary),
            ...Codebase::classesInCategory(Category::Dto),
            'Cbox\Cms\Contracts\Attributes',
            Request::class,
            Response::class,
        ]);
    }
});

arch('rest: a REST controller is a final readonly class with no method but its constructor and __invoke()', function (): void {
    $violations = [];

    foreach (restControllerTypes() as $type) {
        $class = new ReflectionClass($type->fqcn());
        $methods = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $class->getMethods());
        sort($methods);

        if (! $class->isFinal() || ! $class->isReadOnly()) {
            $violations[] = sprintf('%s (%s) is not a final readonly class.', $type->fqcn(), Codebase::relative($type->path));
        }

        if ($methods !== ['__construct', '__invoke']) {
            $violations[] = sprintf('%s (%s) has the methods %s; a REST controller has only __construct and __invoke, and its logic lives in a Boundary.', $type->fqcn(), Codebase::relative($type->path), implode(', ', $methods));
        }
    }

    Rules::none($violations, 'The REST controllers hold no logic (GUARDRAILS 2.1):');
});
