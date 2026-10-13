<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\TypeEditors\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\DefaultToken;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldDefault;
use Cbox\Cms\Generators\TypeEditors\Domain\DefaultKind;
use JsonException;

/**
 * The form-only default of a field as the editor descriptor carries it (gap G2, GUARDRAILS 2.2):
 * one JSON object, `{"kind":"constant","value":<value>}` with the value as the blueprint gives it,
 * which is the record's JSON form for every kind a default takes, or `{"kind":"token","token":
 * "today"}` or `"now"` for the tokens the form resolves from the clock. The domain writes no
 * JSON, so TypeEditorCompiler is given this as a closure.
 */
#[Internal]
final readonly class DefaultJson
{
    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    public static function of(FieldDefault $default): string
    {
        $document = $default->token instanceof DefaultToken
            ? ['kind' => DefaultKind::Token->value, 'token' => $default->token->value]
            : ['kind' => DefaultKind::Constant->value, 'value' => $default->values ?? $default->constant];

        try {
            return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, 'The default of a field cannot be written as JSON: '.$exception->getMessage());
        }
    }
}
