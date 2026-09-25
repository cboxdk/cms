<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs vendor/bin/phpstan from the monorepo root, so the tests can check the effective
 * configuration and not just the text of the neon files.
 */
final readonly class Phpstan
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    private function __construct(private array $parameters) {}

    /**
     * The resolved parameters of a configuration file, relative to the monorepo root.
     *
     * @throws JsonException
     */
    public static function parameters(string $configuration): self
    {
        $process = self::run(['dump-parameters', '--json', '--configuration='.$configuration]);

        if (! $process->isSuccessful()) {
            throw new RuntimeException("phpstan dump-parameters failed for {$configuration}: {$process->getErrorOutput()}");
        }

        $parameters = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($parameters)) {
            throw new RuntimeException('phpstan dump-parameters did not print a JSON object.');
        }

        /** @var array<string, mixed> $parameters */
        return new self($parameters);
    }

    /**
     * Analyses one file with the monorepo configuration.
     *
     * @throws JsonException
     */
    public static function analyse(string $file): PhpstanAnalysis
    {
        $process = self::run(['analyse', '--no-progress', '--error-format=json', $file]);

        return PhpstanAnalysis::fromJson($process->getExitCode() ?? -1, $process->getOutput());
    }

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function value(string $key): mixed
    {
        if (! array_key_exists($key, $this->parameters)) {
            throw new RuntimeException("PHPStan has no parameter {$key}.");
        }

        return $this->parameters[$key];
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->value($key);

        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("PHPStan parameter {$key} is not a list.");
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new RuntimeException("PHPStan parameter {$key} holds a value that is not a string.");
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @param  list<string>  $arguments
     */
    private static function run(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, 'vendor/bin/phpstan', ...$arguments], self::root(), timeout: 300);
        $process->run();

        return $process;
    }
}
