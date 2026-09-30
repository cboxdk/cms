<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Adapter\FileOpenApiDocuments;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\OpenApiDocument;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\CreateNoteCodec;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/*
 * The OpenAPI document as openapi.json next to the registry cache (GUARDRAILS 2.1): what the file
 * does beyond OpenApiDocumentsBehaviour. It refuses a directory that names a stream wrapper before
 * it touches it, leaves no temporary file, takes the kernel's receipt and problem details schemas
 * from the contracts module, and names a schema the installation lacks.
 */

afterEach(function (): void {
    RegistryFixtures::cleanUp();
});

it('refuses a directory that names a stream wrapper before it touches it', function (): void {
    $documents = new FileOpenApiDocuments('ftp://example.test/cache', CreateNoteCodec::codecs(), new QueryCodecs);

    expect(static fn () => $documents->write(new OpenApiDocument("{}\n")))
        ->toThrow(RegistryCacheUnwritable::class, 'ftp://example.test/cache');
});

it('leaves only the document in the directory after a write', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);
    $documents = RegistryFixtures::documents($directory);

    $documents->write($documents->describe(CompiledRegistry::empty()));

    expect(RegistryFixtures::files($directory))->toBe([FileOpenApiDocuments::FILE]);
});

it('puts the kernel\'s receipt and problem details schemas in the document as they are, each with an $id', function (): void {
    $directory = RegistryFixtures::scratch();
    $document = json_decode(RegistryFixtures::documents($directory)->describe(new RegistryCompiler()->compile(RegistryFixtures::validDiscovery()))->json, false, 512, JSON_THROW_ON_ERROR);
    $receipt = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/packages/contracts/resources/schemas/receipt.v1.json'), false, 512, JSON_THROW_ON_ERROR);
    $schema = static function (mixed $document, string $name): stdClass {
        $schema = $document instanceof stdClass && $document->components instanceof stdClass && $document->components->schemas instanceof stdClass ? $document->components->schemas->{$name} : null;

        return $schema instanceof stdClass ? $schema : throw new RuntimeException('The document has no schema '.$name.'.');
    };

    expect($receipt)->toBeInstanceOf(stdClass::class)
        ->and($schema($document, 'Receipt')->title)->toBe($receipt instanceof stdClass ? $receipt->title : null)
        ->and($schema($document, 'Receipt')->{'$id'})->toBe('urn:cbox-cms:receipt:v1')
        ->and($schema($document, 'Problem')->{'$id'})->toBe('urn:cbox-cms:problem:v1')
        ->and($schema($document, 'command.fixture.note.create.v1')->title)->toBe('fixture.note.create, version 1');
});

it('names the kernel schema an installation lacks', function (): void {
    $root = RegistryFixtures::scratch();
    mkdir($root);
    $documents = new FileOpenApiDocuments($root, CreateNoteCodec::codecs(), new QueryCodecs, root: $root);

    expect(static fn (): OpenApiDocument => $documents->describe(CompiledRegistry::empty()))
        ->toThrow(UnexpectedValueException::class, 'The kernel schema '.$root.'/packages/contracts/resources/schemas/receipt.v1.json of cboxdk/cms does not exist or cannot be read. Install cboxdk/cms again.');
});
