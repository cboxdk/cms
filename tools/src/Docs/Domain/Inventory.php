<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;

/**
 * The inventory rule: which public extension points the kernel packages have (GUARDRAILS 2.4, PRD
 * 14.4). It reads declarations, not loaded classes, so it works on any tree:
 *
 * - every interface, attribute class (#[Attribute]) and trait in packages/<package>/src that is not
 *   #[Internal];
 * - every class there that carries #[Command] or #[Hook], #[Internal] or not: a command and its
 *   hook points are public by what they are;
 * - every class there that carries #[PanelPoint] and is not #[Internal]: an internal panel point is
 *   the core's own wiring, never contributable, so it is no extension point;
 * - every *.json schema below packages/<package>/resources/schemas;
 * - minus the exclusions that have a reason.
 *
 * An exclusion without a reason excludes nothing, and one that names nothing the rule finds is
 * stale; both are findings.
 */
final readonly class Inventory
{
    public const string ATTRIBUTE = 'Attribute';

    public const string INTERNAL = Internal::class;

    public const string COMMAND = Command::class;

    public const string HOOK = Hook::class;

    public const string PANEL_POINT = PanelPoint::class;

    /**
     * @param  array<string, ExtensionPoint>  $points  by name, sorted
     * @param  array<string, string>  $excluded  the reason of each applied exclusion, by name
     * @param  list<Finding>  $findings
     */
    private function __construct(
        public array $points,
        public array $excluded,
        public array $findings,
    ) {}

    /**
     * @param  list<PhpFile>  $sources  the PHP files below packages/<package>/src
     * @param  list<string>  $schemas  the repo-relative paths of the JSON schemas below packages/<package>/resources/schemas
     * @param  list<Exclusion>  $exclusions
     */
    public static function of(array $sources, array $schemas, array $exclusions): self
    {
        $found = [];

        foreach ($sources as $file) {
            foreach ($file->types as $type) {
                $kind = self::kindOf($type);

                if ($kind instanceof ExtensionPointKind) {
                    $found[$type->name] = new ExtensionPoint($type->name, $kind);
                }
            }
        }

        foreach ($schemas as $schema) {
            $found[$schema] = new ExtensionPoint($schema, ExtensionPointKind::Schema);
        }

        $points = $found;
        $excluded = [];
        $findings = [];

        foreach ($exclusions as $exclusion) {
            if (! array_key_exists($exclusion->name, $found)) {
                $findings[] = Finding::about($exclusion->name, 'excluded from the inventory of extension points, but packages/*/src and packages/*/resources/schemas have no such interface, attribute class, trait, #[Command], #[Hook] or #[PanelPoint] class or schema that is not #[Internal]; remove the stale exclusion');
            }

            if (! $exclusion->hasReason()) {
                $findings[] = Finding::about($exclusion->name, 'excluded from the inventory of extension points without a reason, so it is not excluded; give the reason');

                continue;
            }

            unset($points[$exclusion->name]);
            $excluded[$exclusion->name] = $exclusion->reason;
        }

        ksort($points, SORT_STRING);

        return new self($points, $excluded, $findings);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->points);
    }

    /**
     * Whether the name is a trait of the inventory, which a shared contract suite is.
     */
    public function isTrait(string $name): bool
    {
        return ($this->points[$name] ?? null)?->kind === ExtensionPointKind::Trait;
    }

    private static function kindOf(DeclaredType $type): ?ExtensionPointKind
    {
        if ($type->kind === TypeKind::Class_ && $type->has(self::COMMAND)) {
            return ExtensionPointKind::Command;
        }

        if ($type->kind === TypeKind::Class_ && $type->has(self::HOOK)) {
            return ExtensionPointKind::Hook;
        }

        if ($type->has(self::INTERNAL)) {
            return null;
        }

        if ($type->kind === TypeKind::Class_ && $type->has(self::PANEL_POINT)) {
            return ExtensionPointKind::PanelPoint;
        }

        return match (true) {
            $type->kind === TypeKind::Interface => ExtensionPointKind::Interface,
            $type->kind === TypeKind::Trait => ExtensionPointKind::Trait,
            $type->kind === TypeKind::Class_ && $type->has(self::ATTRIBUTE) => ExtensionPointKind::Attribute,
            default => null,
        };
    }
}
