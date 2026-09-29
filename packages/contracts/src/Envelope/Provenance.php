<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Where a command's content came from, for agents and ingestion (PRD 5.5, 6.1): the model and its
 * version, the parameters it ran with, a reference to the prompt and the sources. A person's
 * command has none of it: new Provenance().
 *
 * Parameters and a prompt need a model. A parameter name appears at most once, and the parameters
 * are sorted by name. A source appears at most once, and the sources keep their order.
 */
#[Experimental]
final readonly class Provenance
{
    /** @var list<ModelParameter> */
    public array $parameters;

    /** @var list<SourceReference> */
    public array $sources;

    /**
     * @param  list<ModelParameter>  $parameters
     * @param  list<SourceReference>  $sources
     */
    public function __construct(
        public ?GenerationModel $model = null,
        array $parameters = [],
        public ?PromptReference $prompt = null,
        array $sources = [],
    ) {
        if (! $model instanceof GenerationModel && ($parameters !== [] || $prompt instanceof PromptReference)) {
            throw InvalidEnvelope::provenanceWithoutModel();
        }

        $byName = [];

        foreach ($parameters as $parameter) {
            if (isset($byName[$parameter->name])) {
                throw InvalidEnvelope::duplicateProvenance('parameter', $parameter->name);
            }

            $byName[$parameter->name] = $parameter;
        }

        ksort($byName, SORT_STRING);

        $seen = [];

        foreach ($sources as $source) {
            if (isset($seen[$source->value])) {
                throw InvalidEnvelope::duplicateProvenance('source', $source->value);
            }

            $seen[$source->value] = $source;
        }

        $this->parameters = array_values($byName);
        $this->sources = $sources;
    }

    public function isEmpty(): bool
    {
        return ! $this->model instanceof GenerationModel && $this->sources === [];
    }
}
