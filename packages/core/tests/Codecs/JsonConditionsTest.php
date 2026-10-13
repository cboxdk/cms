<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Validation\Condition;
use Cbox\Cms\Contracts\Validation\ExtensionRules;
use Cbox\Cms\Contracts\Validation\FieldReference;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\Rule;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\WhenHidden;
use Cbox\Cms\Core\Codecs\Boundary\JsonConditions;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;

/*
 * The conditions a record codec checks once every value passed its rules (gap G3): a shown field
 * the validator requires needs a value, a hidden field of when_hidden: clear has none, nested
 * fields read their group's siblings and extension fields sit under ext, and a field withheld
 * above the caller's access is never asked for.
 */

function conditionalRules(): TypeRules
{
    $text = static fn (string $handle, Presence $presence, ?Condition $when = null, ?WhenHidden $whenHidden = null): FieldRules => new FieldRules(new FieldHandle($handle), $presence, [new Rule(RuleName::String)], when: $when, whenHidden: $whenHidden);
    $external = Condition::eq(FieldReference::sibling('kind'), 'external');

    return new TypeRules(
        [
            $text('kind', Presence::Optional),
            $text('url', Presence::Required, $external, WhenHidden::Clear),
            $text('note', Presence::Required, Condition::notNull(FieldReference::sibling('kind')), WhenHidden::Keep),
            new FieldRules(new FieldHandle('parts'), Presence::Optional, [new Rule(RuleName::List)], [
                $text('label', Presence::Required, Condition::eq(new FieldReference(1, new FieldHandle('kind')), 'external'), WhenHidden::Clear),
            ]),
        ],
        [new ExtensionRules(new FieldNamespace('acme'), [$text('flag', Presence::Optional), $text('code', Presence::Optional, Condition::notNull(FieldReference::sibling('flag')), WhenHidden::Clear)])],
    );
}

/**
 * The path the check refuses the document at, or null when it passes.
 *
 * @param  array<string, ClassificationAccess>  $classified
 */
function conditionRefusal(string $json, ClassificationAccess $access = ClassificationAccess::Sensitive, array $classified = []): ?string
{
    try {
        JsonConditions::check(JsonText::decode($json), conditionalRules(), $access, $classified);
    } catch (DecodingFailed $failure) {
        expect($failure->errorCode->value)->toBe('json_invalid');

        return $failure->path?->toString() ?? '';
    }

    return null;
}

it('requires a shown field and refuses a value on a hidden one of clear, the first in the validator\'s order', function (string $json, ?string $path): void {
    expect(conditionRefusal($json))->toBe($path);
})->with([
    'nothing shown' => ['{"cms_id":"x"}', null],
    'shown and missing' => ['{"kind":"external","note":"n"}', 'url'],
    'shown and null' => ['{"kind":"external","note":"n","url":null}', 'url'],
    'shown and set' => ['{"kind":"external","note":"n","url":"https://example.org"}', null],
    'hidden with a value under clear' => ['{"kind":"page","note":"n","url":"https://example.org"}', 'url'],
    'hidden with a value under keep' => ['{"note":"n"}', null],
    'a nested field reading its group\'s sibling' => ['{"kind":"external","note":"n","url":"u","parts":[{"label":"a"},{}]}', 'parts[1].label'],
    'a nested field hidden with a value' => ['{"kind":"page","note":"n","parts":[{"label":"a"}]}', 'parts[0].label'],
    'an extension field hidden with a value' => ['{"ext":{"acme":{"code":"c"}}}', 'ext.acme.code'],
    'an extension field shown' => ['{"ext":{"acme":{"flag":"f","code":"c"}}}', null],
]);

it('never asks for a field withheld above the caller\'s access, and asks a caller who may read it', function (): void {
    $classified = ['url' => ClassificationAccess::Internal];

    expect(conditionRefusal('{"kind":"external","note":"n"}', ClassificationAccess::Public, $classified))->toBeNull()
        ->and(conditionRefusal('{"kind":"external","note":"n"}', ClassificationAccess::Internal, $classified))->toBe('url');
});
