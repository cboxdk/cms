<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\TypeScript;

use Cbox\Cms\Tests\Support\Phpstan;
use JsonException;
use RuntimeException;
use stdClass;
use Symfony\Component\Process\Process;

/**
 * Runs the client validation of the component kit's EntryForm in Node (GUARDRAILS 9, Codecs; PRD
 * 11.8): validateEntry() of js/ui-kit/src/components/entry-form on the fields of each case with a
 * type editor descriptor, through run-entry-form.ts, which compiles the kit's modules without
 * React and the TypeScript of the kernel contracts they read as they are, with the TypeScript the
 * JS toolchain pins. Node runs it with its type stripping, and needs `npm ci`.
 */
final readonly class EntryFormValidation
{
    public const string HARNESS = __DIR__.'/run-entry-form.ts';

    /**
     * The issues of each case, in order, each `['path' => ?string, 'code' => string]`.
     *
     * @param  string  $descriptor  the type editor descriptor, as type-editor.v1.json writes it
     * @param  list<array{fields: array<array-key, mixed>, stage: 'write'|'release'}>  $cases
     * @return list<list<array{path: ?string, code: string}>>
     *
     * @throws JsonException
     */
    public static function run(string $descriptor, array $cases): array
    {
        $root = Phpstan::root();

        if (! is_dir($root.'/node_modules/typescript')) {
            throw new RuntimeException('node_modules is missing or stale. Run `npm ci` in the monorepo root.');
        }

        $input = sprintf('{"descriptor":%s,"cases":%s}', $descriptor, json_encode(array_map(
            static fn (array $case): array => ['fields' => $case['fields'] === [] ? new stdClass : $case['fields'], 'stage' => $case['stage']],
            $cases,
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $process = new Process(['node', '--experimental-strip-types', '--no-warnings', self::HARNESS], $root, null, $input, 120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('The client validation of EntryForm did not run: '.$process->getErrorOutput().$process->getOutput());
        }

        /** @var list<list<array{path: ?string, code: string}>> $issues */
        $issues = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);

        return $issues;
    }
}
