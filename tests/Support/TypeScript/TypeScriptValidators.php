<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\TypeScript;

use Cbox\Cms\Tests\Support\Phpstan;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs the TypeScript validators that cms:generate writes in Node (GUARDRAILS 9, Codecs): each
 * case's validator on its document, through run-validators.ts, which compiles the generated
 * modules as they are with the TypeScript the JS toolchain pins. Node runs it with its type
 * stripping, and needs `npm ci`.
 */
final readonly class TypeScriptValidators
{
    public const string HARNESS = __DIR__.'/run-validators.ts';

    /**
     * The verdict of each case, in order: `['valid' => true]`, or `['valid' => false, 'path' =>
     * ?string, 'reason' => string]`.
     *
     * @param  string  $directory  the generated TypeScript directory, relative to the root
     * @param  list<array{module: string, validator: string, document: string}>  $cases
     * @return list<array{valid: bool, path?: ?string, reason?: string}>
     *
     * @throws JsonException
     */
    public static function run(string $directory, array $cases): array
    {
        $root = Phpstan::root();

        if (! is_dir($root.'/node_modules/typescript')) {
            throw new RuntimeException('node_modules is missing or stale. Run `npm ci` in the monorepo root.');
        }

        $process = new Process(['node', '--experimental-strip-types', '--no-warnings', self::HARNESS, $root.'/'.$directory], $root, null, json_encode($cases, JSON_THROW_ON_ERROR), 120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('The TypeScript validators did not run: '.$process->getErrorOutput().$process->getOutput());
        }

        /** @var list<array{valid: bool, path?: ?string, reason?: string}> $verdicts */
        $verdicts = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);

        return $verdicts;
    }

    /**
     * The verdict of one document: null when the validator accepts it, and otherwise the path of
     * the first value it refuses, '' for the document itself.
     *
     * @throws JsonException
     */
    public static function refusedAt(string $directory, string $module, string $validator, string $document): ?string
    {
        $verdict = self::run($directory, [['module' => $module, 'validator' => $validator, 'document' => $document]])[0];

        return $verdict['valid'] ? null : ($verdict['path'] ?? '');
    }
}
