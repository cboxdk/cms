<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support;

use JsonException;
use RuntimeException;

/**
 * Reads the composer.json of a package in packages/ for the smoke tests.
 */
final readonly class PackageManifest
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(private array $data) {}

    /**
     * @throws JsonException
     */
    public static function of(string $package): self
    {
        $path = dirname(__DIR__, 2).'/packages/'.$package.'/composer.json';
        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException("{$path} is not a JSON object.");
        }

        /** @var array<string, mixed> $data */
        return new self($data);
    }

    public function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, string> package name to version constraint
     */
    public function requires(): array
    {
        return $this->stringMap($this->data['require'] ?? null);
    }

    /**
     * @return array<string, string> namespace prefix to directory
     */
    public function psr4(): array
    {
        $autoload = $this->data['autoload'] ?? null;

        return $this->stringMap(is_array($autoload) ? ($autoload['psr-4'] ?? null) : null);
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $map[(string) $key] = $item;
            }
        }

        return $map;
    }
}
