<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\PortableText;

/*
 * The rules of a rich text field (PRD 11.10, 11.12): a list of Portable Text blocks with the
 * styles, decorator marks, list kinds and link kinds the field allows, unique keys, and marks that
 * are decorators or the keys of the block's mark definitions.
 */

/**
 * A document read from the JSON of its blocks.
 */
function portableDocument(string $blocks): ListValue
{
    $document = JsonValues::fieldValue(JsonText::decode('{"body":'.$blocks.'}')->body, new FieldPath('body'));

    expect($document)->toBeInstanceOf(ListValue::class);

    return $document instanceof ListValue ? $document : new ListValue;
}

/**
 * Checks a document with the styles normal and h2, the marks strong and em, bullet lists and url links.
 */
function checkPortable(string $blocks): void
{
    PortableText::check(portableDocument($blocks), new FieldPath('body'), ['normal', 'h2'], ['strong', 'em'], ['bullet'], ['url']);
}

it('accepts blocks with styles, lists, levels, decorators and links the field allows', function (): void {
    checkPortable(<<<'JSON'
        [
          {"_type":"block","_key":"a","style":"h2","children":[{"_type":"span","_key":"a1","text":"Title"}]},
          {"_type":"block","_key":"b","listItem":"bullet","level":2,"markDefs":[{"_type":"link","_key":"l","href":"https://example.org/x"}],
           "children":[{"_type":"span","_key":"b1","text":"read","marks":["strong","l"]},{"_type":"span","_key":"b2","text":" on","marks":[]}],
           "custom":{"kept":true}},
          {"_type":"block","_key":"c","markDefs":[],"children":[{"_type":"span","_key":"a1","text":"same span key, other block"}]},
          {"_type":"block","_key":"d","listItem":"bullet","level":1,"children":[{"_type":"span","_key":"d1","text":"level one"}]}
        ]
        JSON);

    expect(true)->toBeTrue();
});

it('accepts an empty document', function (): void {
    checkPortable('[]');

    expect(true)->toBeTrue();
});

it('refuses a document that breaks a rule, naming the value', function (string $blocks, string $path, string $reason): void {
    $failure = Failures::of(static fn () => checkPortable($blocks));

    expect($failure->errorCode)->toBe(ErrorCode::JsonInvalid)
        ->and($failure->path?->toString())->toBe($path)
        ->and($failure->reason)->toBe($reason);
})->with([
    'a block that is not an object' => ['["x"]', 'body[0]', 'is not an object'],
    'a block of another type' => ['[{"_type":"image","_key":"a"}]', 'body[0]._type', 'is not "block": a rich text field of the blueprint schema v1 holds only blocks'],
    'a block without a type' => ['[{"_key":"a"}]', 'body[0]._type', 'is missing'],
    'a type that is not text' => ['[{"_type":1,"_key":"a"}]', 'body[0]._type', 'is not a string'],
    'a block without a key' => ['[{"_type":"block","children":[]}]', 'body[0]._key', 'is missing'],
    'an empty key' => ['[{"_type":"block","_key":"","children":[]}]', 'body[0]._key', 'is empty'],
    'a key twice' => ['[{"_type":"block","_key":"a","children":[{"_type":"span","_key":"s","text":""}]},{"_type":"block","_key":"a"}]', 'body[1]._key', '"a" is the key of another block of the document'],
    'a style the field does not allow' => ['[{"_type":"block","_key":"a","style":"h3"}]', 'body[0].style', 'is the style "h3", which the field does not allow (normal, h2)'],
    'a list kind the field does not allow' => ['[{"_type":"block","_key":"a","listItem":"number"}]', 'body[0].listItem', 'is the list kind "number", which the field does not allow (bullet)'],
    'a level without a list' => ['[{"_type":"block","_key":"a","level":1}]', 'body[0].level', 'is given without a listItem'],
    'a level of zero' => ['[{"_type":"block","_key":"a","listItem":"bullet","level":0}]', 'body[0].level', 'is not an integer of 1 or more'],
    'a level that is text' => ['[{"_type":"block","_key":"a","listItem":"bullet","level":"1"}]', 'body[0].level', 'is not an integer of 1 or more'],
    'mark definitions that are not a list' => ['[{"_type":"block","_key":"a","markDefs":{}}]', 'body[0].markDefs', 'is not a list'],
    'a mark definition that is not an object' => ['[{"_type":"block","_key":"a","markDefs":[1]}]', 'body[0].markDefs[0]', 'is not an object'],
    'a mark definition of another kind' => ['[{"_type":"block","_key":"a","markDefs":[{"_type":"entry","_key":"m"}]}]', 'body[0].markDefs[0]._type', 'is the mark definition "entry", which the field does not allow (link)'],
    'a link without an address' => ['[{"_type":"block","_key":"a","markDefs":[{"_type":"link","_key":"m"}]}]', 'body[0].markDefs[0].href', 'is missing'],
    'a link that is not absolute' => ['[{"_type":"block","_key":"a","markDefs":[{"_type":"link","_key":"m","href":"/x"}]}]', 'body[0].markDefs[0].href', 'is not an absolute http or https URL'],
    'a link of another scheme' => ['[{"_type":"block","_key":"a","markDefs":[{"_type":"link","_key":"m","href":"javascript:alert(1)"}]}]', 'body[0].markDefs[0].href', 'is not an absolute http or https URL'],
    'a mark definition key twice' => ['[{"_type":"block","_key":"a","markDefs":[{"_type":"link","_key":"m","href":"https://a.example"},{"_type":"link","_key":"m","href":"https://b.example"}]}]', 'body[0].markDefs[1]._key', '"m" is the key of another mark definition of the block'],
    'no children' => ['[{"_type":"block","_key":"a"}]', 'body[0].children', 'is missing'],
    'children that are not a list' => ['[{"_type":"block","_key":"a","children":"x"}]', 'body[0].children', 'is not a list'],
    'no spans' => ['[{"_type":"block","_key":"a","children":[]}]', 'body[0].children', 'is empty: a block has at least one span'],
    'a child that is not a span' => ['[{"_type":"block","_key":"a","children":[{"_type":"image","_key":"s"}]}]', 'body[0].children[0]._type', 'is not "span"'],
    'a span without text' => ['[{"_type":"block","_key":"a","children":[{"_type":"span","_key":"s"}]}]', 'body[0].children[0].text', 'is missing'],
    'a span key twice' => ['[{"_type":"block","_key":"a","children":[{"_type":"span","_key":"s","text":""},{"_type":"span","_key":"s","text":""}]}]', 'body[0].children[1]._key', '"s" is the key of another span of the block'],
    'marks that are not a list' => ['[{"_type":"block","_key":"a","children":[{"_type":"span","_key":"s","text":"","marks":"strong"}]}]', 'body[0].children[0].marks', 'is not a list'],
    'a decorator the field does not allow' => ['[{"_type":"block","_key":"a","children":[{"_type":"span","_key":"s","text":"","marks":["code"]}]}]', 'body[0].children[0].marks[0]', 'is neither a decorator the field allows (strong, em) nor the key of a mark definition of the block'],
    'a mark that is not text' => ['[{"_type":"block","_key":"a","children":[{"_type":"span","_key":"s","text":"","marks":[1]}]}]', 'body[0].children[0].marks[0]', 'is neither a decorator the field allows (strong, em) nor the key of a mark definition of the block'],
    'the key of another block\'s mark definition' => ['[{"_type":"block","_key":"a","markDefs":[{"_type":"link","_key":"m","href":"https://a.example"}],"children":[{"_type":"span","_key":"s","text":""}]},{"_type":"block","_key":"b","children":[{"_type":"span","_key":"s","text":"","marks":["m"]}]}]', 'body[1].children[0].marks[0]', 'is neither a decorator the field allows (strong, em) nor the key of a mark definition of the block'],
]);

it('refuses every link and names none allowed when the field allows no links', function (): void {
    $failure = Failures::of(static fn () => PortableText::check(
        portableDocument('[{"_type":"block","_key":"a","markDefs":[{"_type":"link","_key":"m","href":"https://a.example"}],"children":[{"_type":"span","_key":"s","text":""}]}]'),
        new FieldPath('body'),
        ['normal'],
        [],
        [],
        [],
    ));

    expect($failure->reason)->toBe('is the mark definition "link", which the field does not allow (none)');
});
