<?php

declare(strict_types=1);

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Symfony\Component\Yaml\Yaml;

// Validates blueprint files against blueprint.v1.json from the installed cboxdk/cms-contracts, the
// schema cms:generate validates against. Read YAML with PARSE_OBJECT_FOR_MAP, so that a map stays
// an object, and validate with CompliantValidator, which never writes defaults into the data.

it('accepts the blueprint file', function (string $file): void {
    $root = dirname(__DIR__, 3);
    $schema = file_get_contents($root.'/vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json')
        ?: throw new RuntimeException('Cannot read blueprint.v1.json.');
    $blueprint = Yaml::parseFile($root.'/'.$file, Yaml::PARSE_OBJECT_FOR_MAP);

    $error = new CompliantValidator()->validate($blueprint, $schema)->error();

    expect($error instanceof ValidationError ? new ErrorFormatter()->format($error) : [])->toBe([]);
})->with([
    'the type article' => ['packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/article.yaml'],
    'the extension of another owner\'s type' => ['packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/extension.yaml'],
    'the type product with the addon field type acme:colour' => ['packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/addon-field-type.yaml'],
]);
