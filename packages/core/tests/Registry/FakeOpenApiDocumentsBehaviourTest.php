<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Domain\OpenApiDocuments;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeOpenApiDocuments;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * OpenApiDocumentsBehaviour against the fake the registry's action tests use.
 */
final class FakeOpenApiDocumentsBehaviourTest extends TestCase
{
    use OpenApiDocumentsBehaviour;

    #[Override]
    protected function openApiDocuments(): OpenApiDocuments
    {
        return new FakeOpenApiDocuments('fixture.note.create@1');
    }

    #[Override]
    protected function unwritableOpenApiDocuments(): OpenApiDocuments
    {
        $documents = new FakeOpenApiDocuments('fixture.note.create@1');
        $documents->refuseWrites('file_put_contents(): Permission denied');

        return $documents;
    }

    #[Override]
    protected function writtenOpenApi(OpenApiDocuments $documents): ?string
    {
        if (! $documents instanceof FakeOpenApiDocuments) {
            throw new LogicException('The case reads documents this class did not make.');
        }

        return $documents->written?->json;
    }
}
