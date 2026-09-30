<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Adapter\FileOpenApiDocuments;
use Cbox\Cms\Core\Registry\Domain\OpenApiDocuments;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\CreateNoteCodec;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * OpenApiDocumentsBehaviour against FileOpenApiDocuments in scratch directories. The unwritable
 * documents lie below a file, so no file can be written there, whoever runs the test.
 */
final class FileOpenApiDocumentsBehaviourTest extends TestCase
{
    use OpenApiDocumentsBehaviour;

    #[Override]
    protected function tearDown(): void
    {
        RegistryFixtures::cleanUp();

        parent::tearDown();
    }

    #[Override]
    protected function openApiDocuments(): OpenApiDocuments
    {
        $directory = RegistryFixtures::scratch();
        mkdir($directory);

        return RegistryFixtures::documents($directory);
    }

    #[Override]
    protected function unwritableOpenApiDocuments(): OpenApiDocuments
    {
        $directory = RegistryFixtures::scratch();
        mkdir($directory);
        file_put_contents($directory.'/cache', 'a file where the directory should be');

        return new FileOpenApiDocuments($directory.'/cache', CreateNoteCodec::codecs(), new QueryCodecs);
    }

    #[Override]
    protected function writtenOpenApi(OpenApiDocuments $documents): ?string
    {
        if (! $documents instanceof FileOpenApiDocuments) {
            throw new LogicException('The case reads documents this class did not make.');
        }

        $directory = new ReflectionProperty($documents, 'directory')->getValue($documents);
        $path = (is_string($directory) ? $directory : '').'/'.FileOpenApiDocuments::FILE;

        return is_file($path) ? (string) file_get_contents($path) : null;
    }
}
