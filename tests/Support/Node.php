<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support;

use Closure;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs the JS toolchain from the monorepo root (GUARDRAILS 10, gates 1 and 4), so the tests
 * check what tsc, ESLint and Prettier actually do with the shared configuration in js/tooling.
 * The packages come from `npm ci`; without them the tests fail and say so.
 */
final readonly class Node
{
    /**
     * Where probe files go: inside the root tsconfig.json, so tsc and the ESLint project
     * service see them exactly like the workbench's generated TypeScript.
     */
    public const string PROBE_DIRECTORY = 'workbench/resources/js';

    /**
     * Runs a command from the monorepo root, for example ['npm', 'run', 'typecheck'].
     *
     * @param  list<string>  $command
     */
    public static function run(array $command): Process
    {
        $root = Phpstan::root();

        if (! is_dir($root.'/node_modules/@cboxdk/cms-tooling')) {
            throw new RuntimeException('node_modules is missing or stale. Run `npm ci` in the monorepo root.');
        }

        $process = new Process($command, $root, null, null, 180);

        // A whole-project run such as tsc must not see another process's probe (JsToolchainLock).
        JsToolchainLock::shared(static fn (): int => $process->run());

        return $process;
    }

    /**
     * Runs a tool from node_modules/.bin.
     *
     * @param  list<string>  $arguments
     */
    public static function tool(string $tool, array $arguments): Process
    {
        return self::run([Phpstan::root().'/node_modules/.bin/'.$tool, ...$arguments]);
    }

    /**
     * Runs a tool that prints JSON and decodes it.
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function json(string $tool, array $arguments): array
    {
        $process = self::tool($tool, $arguments);

        if (! $process->isSuccessful()) {
            throw new RuntimeException("{$tool} failed: {$process->getErrorOutput()}{$process->getOutput()}");
        }

        return self::decode($process->getOutput(), $tool);
    }

    /**
     * Reads a JSON file relative to the monorepo root.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function jsonFile(string $path): array
    {
        return self::decode((string) file_get_contents(Phpstan::root().'/'.$path), $path);
    }

    /**
     * Writes a probe file below PROBE_DIRECTORY, hands its path relative to the root to the
     * callback and deletes it again, also when the callback throws. The whole of it runs under the
     * exclusive JsToolchainLock, so the tool runs in the callback see this probe and no other, and
     * no other process's tool run sees this one.
     *
     * @template TResult
     *
     * @param  Closure(string): TResult  $callback
     * @return TResult
     */
    public static function withProbe(string $extension, string $code, Closure $callback): mixed
    {
        return JsToolchainLock::exclusive(static function () use ($extension, $code, $callback) {
            $path = self::PROBE_DIRECTORY.'/cms-probe-'.bin2hex(random_bytes(4)).'.'.$extension;
            $absolute = Phpstan::root().'/'.$path;
            file_put_contents($absolute, $code);

            try {
                return $callback($path);
            } finally {
                if (is_file($absolute)) {
                    unlink($absolute);
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private static function decode(string $json, string $source): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("{$source} is not a JSON object.");
        }

        $object = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException("{$source} is not a JSON object.");
            }

            $object[$key] = $value;
        }

        return $object;
    }
}
