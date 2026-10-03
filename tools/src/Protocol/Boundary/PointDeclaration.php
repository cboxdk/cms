<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Protocol\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Stable;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use ReflectionClass;

/**
 * The panel point a point schema's binding binds (PRD 13.4), read with reflection from the class
 * bound to the schema's document, `#`: its #[PanelPoint] gives the point and its stability attribute
 * whether the point is stable. It holds the binding to the declaration: the schema's file is
 * `<name>.v<version>.json`, the binding's version is the point's, the class's short name ends in
 * `V<version>`, the codec is that name with `Codec` before the version, and the point is not
 * #[Internal], which is never contributed to and has no TypeScript.
 */
final readonly class PointDeclaration
{
    private function __construct(
        public PointId $point,
        public bool $stable,
    ) {}

    /**
     * @throws GenerationFailed with generate_schema_invalid
     */
    public static function of(SchemaBinding $binding): self
    {
        $class = $binding->objects['#'] ?? null;

        if ($class === null || ! class_exists($class)) {
            throw self::invalid($binding, 'binds its document to no class');
        }

        $reflection = new ReflectionClass($class);
        $declarations = $reflection->getAttributes(PanelPoint::class);

        if (count($declarations) !== 1) {
            throw self::invalid($binding, sprintf('binds its document to %s, which declares no #[PanelPoint]', $class));
        }

        $declaration = $declarations[0]->newInstance();
        $point = $declaration->id();
        $short = $reflection->getShortName();
        $suffix = 'V'.$declaration->version;

        if ($binding->schema !== sprintf('%s.v%d.json', $declaration->name, $declaration->version) || $binding->version !== $declaration->version) {
            throw self::invalid($binding, sprintf('is not the schema of %s, whose schema is %s.v%d.json at version %d', $point->toString(), $declaration->name, $declaration->version, $declaration->version));
        }

        if (! str_ends_with($short, $suffix) || $binding->codecClass !== substr($short, 0, -strlen($suffix)).'Codec'.$suffix) {
            throw self::invalid($binding, sprintf('names the codec %s for %s; the props class of %s is named with %s at its end, and its codec is that name with Codec before the version', $binding->codecClass, $short, $point->toString(), $suffix));
        }

        if ($reflection->getAttributes(Internal::class) !== []) {
            throw self::invalid($binding, sprintf('binds %s, an #[Internal] point, which no addon contributes to; it has no schema binding and no TypeScript', $point->toString()));
        }

        $stable = $reflection->getAttributes(Stable::class) !== [];

        if ($stable === ($reflection->getAttributes(Experimental::class) !== [])) {
            throw self::invalid($binding, sprintf('binds %s, whose class is not exactly one of #[Stable] and #[Experimental]', $point->toString()));
        }

        return new self($point, $stable);
    }

    private static function invalid(SchemaBinding $binding, string $message): GenerationFailed
    {
        return GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf('The point schema %s %s.', $binding->path(), $message));
    }
}
