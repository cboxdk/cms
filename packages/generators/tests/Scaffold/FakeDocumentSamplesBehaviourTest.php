<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Scaffold;

use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\ArrayLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\NumberLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\ObjectLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Property;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\StringLiteral;
use Cbox\Cms\Generators\Scaffold\Domain\DocumentSamples;
use Cbox\Cms\Generators\Tests\Scaffold\Fakes\FakeDocumentSamples;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * DocumentSamplesBehaviour against the fake the scaffold actions' tests use, given the two samples
 * the behaviour asks for.
 */
final class FakeDocumentSamplesBehaviourTest extends TestCase
{
    use DocumentSamplesBehaviour;

    #[Override]
    protected function documentSamples(): DocumentSamples
    {
        return new FakeDocumentSamples()
            ->withCommand(CommandRef::fromString('entry.create@1'), new ObjectLiteral([
                new Property('entry', new StringLiteral('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01')),
                new Property('fields', new ObjectLiteral([new Property('label', new ArrayLiteral([]))])),
            ]))
            ->withResult(CommandRef::fromString('tally.notes@1'), new ObjectLiteral([new Property('count', new NumberLiteral('0'))]));
    }
}
