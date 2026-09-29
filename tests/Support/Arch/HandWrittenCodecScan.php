<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Closure;
use JsonSerializable;
use ReflectionClass;

/**
 * Finds serialisation written by hand for a class whose JSON form a generated codec fixes
 * (GUARDRAILS 2.2): the classes the kernel's JSON Schemas are bound to, such as the Receipt, the
 * Problem and the RequestEnvelope, are written and read only by the codecs in a directory named
 * Generated.
 *
 * - A file outside a Generated directory that names a bound class and also turns something into
 *   JSON or back, with json_encode(), json_decode(), JsonText, JsonValues or JsonSerializable, is a
 *   finding at the first JSON reference.
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

        foreach ($files as $file) {
            if (self::generated($file->path)) {
                continue;
            }

            $finding = self::serialisation($file, $bound);

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
     * @param  list<string>  $bound
     */
    private static function serialisation(SourceFile $file, array $bound): ?string
    {
        $named = [];
        $json = null;

        foreach ($file->references as $reference) {
            if ($reference->kind === ReferenceKind::ClassName && in_array($reference->name, $bound, true)) {
                $named[$reference->name] = $reference->name;
            }

            $isJson = ($reference->kind === ReferenceKind::Function && in_array($reference->name, self::FUNCTIONS, true))
                || ($reference->kind === ReferenceKind::ClassName && in_array($reference->name, self::CLASSES, true))
                || ($reference->kind === ReferenceKind::Method && $reference->name === 'jsonserialize');

            if ($isJson && ! $json instanceof Reference) {
                $json = $reference;
            }
        }

        if ($named === [] || ! $json instanceof Reference) {
            return null;
        }

        sort($named);

        return sprintf('%s:%d: serialises %s by hand with %s; its JSON form is written and read only by its generated codec.', Codebase::relative($json->path), $json->line, implode(', ', $named), $json->name);
    }

    private static function generated(string $path): bool
    {
        return str_contains($path, '/Generated/');
    }
}
