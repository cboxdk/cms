<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Boundary;

use Cbox\Cms\Tooling\Docs\Domain\Requirements;
use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use UnexpectedValueException;

/**
 * Reads the Requirements of a checkout from its files: `require` and `suggest` of composer.json,
 * `engines.node` of package.json and the image of every service of compose.yaml that names one.
 */
final readonly class RequirementsSources
{
    /**
     * @throws UnexpectedValueException when the root is not a directory, or a file is missing or
     *                                  does not have the shape its tool writes
     */
    public static function read(string $root): Requirements
    {
        $real = realpath($root);

        if ($real === false || ! is_dir($real)) {
            throw new UnexpectedValueException("{$root} is not a directory.");
        }

        $composer = self::json($real, 'composer.json');
        $package = self::json($real, 'package.json');
        $engines = $package['engines'] ?? [];

        if (! is_array($engines)) {
            throw new UnexpectedValueException('package.json engines is not an object.');
        }

        $node = $engines['node'] ?? null;

        if ($node !== null && ! is_string($node)) {
            throw new UnexpectedValueException('package.json engines.node is not a string.');
        }

        return new Requirements(
            self::strings($composer['require'] ?? [], 'composer.json require'),
            self::strings($composer['suggest'] ?? [], 'composer.json suggest'),
            $node,
            self::images($real),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(string $root, string $file): array
    {
        $contents = is_file($root.'/'.$file) ? file_get_contents($root.'/'.$file) : false;

        if ($contents === false) {
            throw new UnexpectedValueException("Cannot read {$file} in {$root}.");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException("{$file} is not valid JSON: {$exception->getMessage()}", 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new UnexpectedValueException("{$file} is not a JSON object.");
        }

        return $decoded;
    }

    /**
     * The service name to image of compose.yaml; a service without an image, which Docker builds,
     * is left out.
     *
     * @return array<string, string>
     */
    private static function images(string $root): array
    {
        try {
            $compose = Yaml::parseFile($root.'/compose.yaml');
        } catch (ParseException $exception) {
            throw new UnexpectedValueException("compose.yaml cannot be read: {$exception->getMessage()}", 0, $exception);
        }

        $services = is_array($compose) ? ($compose['services'] ?? null) : null;

        if (! is_array($services)) {
            throw new UnexpectedValueException('compose.yaml has no services.');
        }

        $images = [];

        foreach ($services as $name => $service) {
            $image = is_array($service) ? ($service['image'] ?? null) : null;

            if (is_string($image)) {
                $images[(string) $name] = $image;
            }
        }

        return $images;
    }

    /**
     * @return array<string, string>
     */
    private static function strings(mixed $values, string $what): array
    {
        if (! is_array($values)) {
            throw new UnexpectedValueException("{$what} is not an object.");
        }

        $strings = [];

        foreach ($values as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                throw new UnexpectedValueException("{$what} has an entry that is not a name and a string.");
            }

            $strings[$name] = $value;
        }

        return $strings;
    }
}
