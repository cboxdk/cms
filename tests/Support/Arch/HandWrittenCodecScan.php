<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Closure;
use JsonSerializable;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionType;

/**
 * Finds serialisation written by hand for a class whose JSON form a generated codec fixes
 * (GUARDRAILS 2.2): the classes the kernel's JSON Schemas are bound to, such as the Receipt, the
 * Problem and the RequestEnvelope, are written and read only by the codecs in a directory named
 * Generated.
 *
 * - A file outside a Generated directory that names a bound class and also turns something into
 *   JSON or back, with json_encode(), json_decode(), JsonText, JsonValues or JsonSerializable, is a
 *   finding at the first JSON reference.
 * - So is a file outside a Generated directory that names a bound class and a JSON helper: a class
 *   declared outside a Generated directory in a file that turns something into JSON or back, with a
 *   public method that takes or gives a stdClass, such as a wrapper of JsonText that encodes an
 *   object, so a hand-written encoding cannot hide behind one.
 * - A class outside a Generated directory that implements JsonCodec is a finding: a codec is
 *   generated, never written by hand.
 * - A bound class that implements JsonSerializable or declares one of SERIALISERS is a finding.
 */
final readonly class HandWrittenCodecScan
{
    /** The functions that turn a value into JSON or back, lowercase as ReferenceScan gives them. */
    public const array FUNCTIONS = ['json_decode', 'json_encode'];

    /** The classes whose use turns a value into JSON or back. */
    public const array CLASSES = [JsonSerializable::class, JsonText::class, JsonValues::class];

    /** Methods a class serialises itself with, lowercase. */
    public const array SERIALISERS = ['__serialize', 'jsonserialize', 'toarray', 'tojson'];

    /**
     * @param  list<SourceFile>  $files
     * @param  list<string>  $bound  the classes a generated codec writes and reads
     * @param  Closure(class-string): bool  $isCodec  whether a declared class implements JsonCodec
     * @return list<string>
     */
    public static function findings(array $files, array $bound, Closure $isCodec): array
    {
        $findings = [];
        $helpers = self::helpers($files);

        foreach ($files as $file) {
            if (self::generated($file->path)) {
                continue;
            }

            $finding = self::serialisation($file, $bound, $helpers);

            if ($finding !== null) {
                $findings[] = $finding;
            }

            foreach ($file->types as $type) {
                if ($type->kind === 'class' && $isCodec($type->fqcn())) {
                    $findings[] = sprintf('%s:%d: %s implements %s by hand; a codec is generated into a directory named Generated.', Codebase::relative($type->path), $type->line, $type->fqcn(), JsonCodec::class);
                }
            }
        }

        foreach ($bound as $class) {
            if (! class_exists($class)) {
                $findings[] = sprintf('%s is bound by a kernel schema but is not a class.', $class);

                continue;
            }

            $reflection = new ReflectionClass($class);
            $methods = array_values(array_filter(self::SERIALISERS, $reflection->hasMethod(...)));

            if ($reflection->implementsInterface(JsonSerializable::class) || $methods !== []) {
                $findings[] = sprintf('%s serialises itself (%s); its JSON form is written only by its generated codec.', $class, implode(', ', $reflection->implementsInterface(JsonSerializable::class) ? ['JsonSerializable', ...$methods] : $methods));
            }
        }

        return $findings;
    }

    /**
     * The classes declared outside a Generated directory in a file that turns something into JSON
     * or back, by their fully qualified name.
     *
     * @param  list<SourceFile>  $files
     * @return array<string, true>
     */
    private static function helpers(array $files): array
    {
        $helpers = [];

        foreach ($files as $file) {
            if (self::generated($file->path) || ! array_any($file->references, self::isJson(...))) {
                continue;
            }

            foreach ($file->types as $type) {
                if (self::handlesObjects($type->fqcn())) {
                    $helpers[$type->fqcn()] = true;
                }
            }
        }

        return $helpers;
    }

    /**
     * Whether a class has a public method that takes or gives a stdClass, the form JSON objects take
     * in PHP.
     */
    private static function handlesObjects(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $types = [$method->getReturnType(), ...array_map(static fn (ReflectionParameter $parameter): ?ReflectionType => $parameter->getType(), $method->getParameters())];

            foreach ($types as $type) {
                if ($type instanceof ReflectionType && preg_match('/(?:^|[|?(&])stdClass(?:$|[|)&])/', (string) $type) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isJson(Reference $reference): bool
    {
        return ($reference->kind === ReferenceKind::Function && in_array($reference->name, self::FUNCTIONS, true))
            || ($reference->kind === ReferenceKind::ClassName && in_array($reference->name, self::CLASSES, true))
            || ($reference->kind === ReferenceKind::Method && $reference->name === 'jsonserialize');
    }

    /**
     * @param  list<string>  $bound
     * @param  array<string, true>  $helpers
     */
    private static function serialisation(SourceFile $file, array $bound, array $helpers): ?string
    {
        $named = [];
        $json = null;
        $own = array_map(static fn (DeclaredType $type): string => $type->fqcn(), $file->types);

        foreach ($file->references as $reference) {
            if ($reference->kind === ReferenceKind::ClassName && in_array($reference->name, $bound, true)) {
                $named[$reference->name] ??= $reference;
            }

            $isJson = self::isJson($reference)
                || ($reference->kind === ReferenceKind::ClassName && isset($helpers[$reference->name]) && ! in_array($reference->name, $own, true));

            if ($isJson && ! $json instanceof Reference) {
                $json = $reference;
            }
        }

        if ($named === []) {
            return null;
        }

        ksort($named, SORT_STRING);
        $first = array_reduce($named, static fn (?Reference $carry, Reference $reference): Reference => $carry instanceof Reference && $carry->line <= $reference->line ? $carry : $reference);
        $json ??= $first instanceof Reference ? self::neighbour($file, $first, $helpers, $own) : null;

        if (! $json instanceof Reference) {
            return null;
        }

        return sprintf('%s:%d: serialises %s by hand with %s; its JSON form is written and read only by its generated codec.', Codebase::relative($json->path), $json->line, implode(', ', array_keys($named)), $json->name);
    }

    /**
     * A JSON helper of the file's own namespace, which the file uses by its short name without an
     * import, so its tokens do not name it (ReferenceScan), placed at the file's first reference to
     * a bound class; or null when its namespace has none.
     *
     * @param  array<string, true>  $helpers
     * @param  list<string>  $own
     */
    private static function neighbour(SourceFile $file, Reference $bound, array $helpers, array $own): ?Reference
    {
        $namespace = $file->types === [] ? $bound->namespace : $file->types[0]->namespace;

        foreach (array_keys($helpers) as $helper) {
            if (! in_array($helper, $own, true) && substr($helper, 0, (int) strrpos($helper, '\\')) === $namespace) {
                return new Reference(ReferenceKind::ClassName, $helper, $namespace, $bound->path, $bound->line);
            }
        }

        return null;
    }

    private static function generated(string $path): bool
    {
        return str_contains($path, '/Generated/');
    }
}
