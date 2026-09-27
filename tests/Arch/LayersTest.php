<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\DeclaredType;
use Cbox\Cms\Tests\Support\Arch\Layer;
use Cbox\Cms\Tests\Support\Arch\Rules;

/*
 * The layers of GUARDRAILS 2.5 over packages/src and workbench/app. Where each layer lives is
 * described under "Hvor ting bor" in CLAUDE.md, and Layer explains how a namespace maps to a
 * layer. Targets and dependencies are listed class by class, so a namespace such as
 * DomainEvents is never mistaken for Domain.
 */

/**
 * @return list<string>
 */
function surfaceClasses(): array
{
    return Codebase::classesIn(...Layer::surfaces());
}

arch('layers: the domain uses only the domain and the contracts', function (): void {
    expect(Codebase::classesIn(Layer::Domain))->toOnlyUse(Codebase::classesIn(Layer::Domain));
});

arch('layers: the domain does not use Illuminate\Http, facades or Eloquent', function (): void {
    Rules::forbid(Codebase::classesIn(Layer::Domain), [
        'Illuminate\Http',
        'Illuminate\Support\Facades',
        'Illuminate\Database\Eloquent',
    ]);
});

arch('layers: actions use only the domain, the contracts and the planners in Actions', function (): void {
    expect(Codebase::classesIn(Layer::Actions))->toOnlyUse(Codebase::classesIn(Layer::Domain, Layer::Actions));
});

arch('layers: surfaces do not use Infrastructure, Adapter or Eloquent', function (): void {
    Rules::forbid(surfaceClasses(), [
        ...Codebase::classesIn(Layer::Infrastructure, Layer::Adapter),
        'Illuminate\Database\Eloquent',
    ]);
});

arch('layers: infrastructure uses only the domain, the contracts, Illuminate\Database, Boundary and casts in Adapter', function (): void {
    expect(Codebase::classesIn(Layer::Infrastructure))->toOnlyUse(Codebase::infrastructureMayUse());
});

arch('layers: infrastructure does not use Illuminate\Http, facades, actions or surfaces', function (): void {
    Rules::forbid(Codebase::classesIn(Layer::Infrastructure), [
        'Illuminate\Http',
        'Illuminate\Support\Facades',
        ...Codebase::classesIn(Layer::Actions),
        ...surfaceClasses(),
    ]);
});

arch('layers: boundary and adapter do not use actions or surfaces', function (): void {
    Rules::forbid(Codebase::classesIn(Layer::Boundary, Layer::Adapter), [
        ...Codebase::classesIn(Layer::Actions),
        ...surfaceClasses(),
    ]);
});

arch('layers: commands, queries, DTOs and receipts sit below Domain', function (): void {
    $violations = [];

    foreach (Codebase::types() as $type) {
        if ($type->category() !== null && $type->layer() !== Layer::Domain) {
            $violations[] = sprintf(
                '%s (%s:%d) is in a %s namespace outside Domain.',
                $type->fqcn(),
                Codebase::relative($type->path),
                $type->line,
                $type->category()->value,
            );
        }
    }

    Rules::none($violations, 'Commands, Queries, Dto and Receipts namespaces belong below Domain (CLAUDE.md, Hvor ting bor).');
});

arch('layers: the contracts package is domain only and has no layer namespaces', function (): void {
    $violations = array_map(
        static fn (DeclaredType $type): string => sprintf('%s (%s:%d)', $type->fqcn(), Codebase::relative($type->path), $type->line),
        array_values(array_filter(
            Codebase::types(),
            static fn (DeclaredType $type): bool => Layer::inContracts($type->namespace) && Layer::segmentsOf($type->namespace) !== [],
        )),
    );

    Rules::none($violations, 'The contracts package is part of the domain (GUARDRAILS 2.5) and may not contain layer namespaces.');
});
