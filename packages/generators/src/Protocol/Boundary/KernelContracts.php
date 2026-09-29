<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Protocol\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;
use Cbox\Cms\Generators\Schema\Boundary\LocalFile;

/**
 * The codec contracts of the kernel's JSON Schemas (ProtocolSchemas::kernel()), read from the
 * schemas of the cboxdk/cms this code is part of, for the generators that write their TypeScript.
 */
#[Internal]
final readonly class KernelContracts
{
    /**
     * @param  string  $root  the root of cboxdk/cms, or null for the one this file is in
     */
    public function __construct(private ?string $root = null) {}

    /**
     * The contract of each kernel schema, in the order of ProtocolSchemas::kernel().
     *
     * @return list<CodecContract>
     *
     * @throws GenerationFailed with generate_schema_missing or generate_schema_invalid, naming every schema
     */
    public function read(): array
    {
        $root = $this->root ?? dirname(__DIR__, 5);
        $contracts = [];
        $problems = [];

        foreach (ProtocolSchemas::kernel() as $binding) {
            $path = ProtocolSchemas::SCHEMA_DIRECTORY.'/'.$binding->schema;
            $json = LocalFile::contents($root.'/'.$path);

            if ($json === null) {
                $problems[] = new GenerationProblem(GenerateErrorCode::SchemaMissing, sprintf('The kernel schema %s of cboxdk/cms does not exist or cannot be read. Install cboxdk/cms again.', $path));

                continue;
            }

            try {
                $contracts[] = JsonSchemaContract::read($json, $binding, ProtocolSchemas::ATTRIBUTE);
            } catch (GenerationFailed $failed) {
                array_push($problems, ...$failed->problems);
            }
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        return $contracts;
    }
}
