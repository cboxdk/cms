<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\PanelTypes;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Generators\PanelTypes\Boundary\JsonSchemaShapes;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\AddonUi;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\ContractShape;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\PointType;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\UiContribution;

/**
 * An addon, reviews, with a contribution of every kind that runs code, to a stable and an
 * experimental point, with a data query two contributions share, a form check and a flow step on
 * a command whose fields refer to a recursive definition, and two commands it issues: the input of
 * the golden module Fixtures/contributions.ts.golden, which cms:panel:types writes for it.
 */
final class PanelTypesFixtures
{
    /** The golden module, relative to this directory. */
    public const string GOLDEN = 'Fixtures/contributions.ts.golden';

    public const string PENDING_RESULT = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "reviews.pending result v1",
          "description": "The reviews waiting for the viewer.",
          "type": "object",
          "additionalProperties": false,
          "required": ["count", "reviews", "next"],
          "properties": {
            "count": {"description": "How many reviews wait.", "type": "integer", "minimum": 0},
            "reviews": {
              "type": "array",
              "maxItems": 20,
              "items": {
                "type": "object",
                "additionalProperties": false,
                "required": ["id", "state"],
                "properties": {
                  "id": {"type": "string", "pattern": "^[0-9a-f-]{36}$"},
                  "state": {"enum": ["pending", "approved", "rejected"]},
                  "note": {"type": ["string", "null"], "maxLength": 200}
                }
              }
            },
            "next": {"anyOf": [{"$ref": "#/$defs/cursor"}, {"type": "null"}]}
          },
          "$defs": {
            "cursor": {"description": "Where the next page starts.", "type": "string", "minLength": 1}
          }
        }
        JSON;

    public const string NOTE_CREATE = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "note.create v1",
          "type": "object",
          "additionalProperties": false,
          "required": ["title", "fields"],
          "properties": {
            "title": {"description": "The note's title, 1 to 80 characters.", "type": "string", "minLength": 1, "maxLength": 80},
            "kind": {"const": "note", "default": "note"},
            "fields": {"$ref": "#/$defs/fields"},
            "tags": {"type": "array", "items": {"type": "string"}}
          },
          "$defs": {
            "fields": {
              "type": "object",
              "properties": {"ext": {"type": "object", "additionalProperties": {"$ref": "#/$defs/fields"}}},
              "additionalProperties": {"$ref": "#/$defs/field_value"}
            },
            "field_value": {
              "description": "A field's value: a string, an integer, a boolean, null, a list of values or an object of values.",
              "type": ["string", "integer", "boolean", "null", "array", "object"],
              "items": {"$ref": "#/$defs/field_value"},
              "additionalProperties": {"$ref": "#/$defs/field_value"}
            }
          }
        }
        JSON;

    public const string REVIEW_REQUEST = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "reviews.request v1",
          "description": "Asks for a review of a note.",
          "type": "object",
          "additionalProperties": false,
          "required": ["note", "priority"],
          "properties": {
            "note": {"type": "string", "format": "uuid"},
            "priority": {"enum": [1, 2, 3, null]},
            "labels": {"type": "object", "additionalProperties": {"type": "string"}},
            "reviewer": {
              "description": "Who reviews it, or anyone when it is left out.",
              "type": "object",
              "additionalProperties": false,
              "required": ["actor"],
              "properties": {"actor": {"type": "string"}, "deadline": {"type": "string", "format": "date-time"}}
            }
          }
        }
        JSON;

    public static function addon(string $root): AddonUi
    {
        $card = new PointType(PointId::fromString('notes.detail.card@1'), 'NoteCardV1', false);
        $toolbar = new PointType(PointId::fromString('notes.list.toolbar@1'), 'NoteToolbarV1', true);
        $pending = new ContractShape(CommandRef::fromString('reviews.pending@1'), JsonSchemaShapes::read(self::PENDING_RESULT, 'the result of reviews.pending@1'));
        $create = new ContractShape(CommandRef::fromString('note.create@1'), JsonSchemaShapes::read(self::NOTE_CREATE, 'the schema of note.create@1'));
        $request = new ContractShape(CommandRef::fromString('reviews.request@1'), JsonSchemaShapes::read(self::REVIEW_REQUEST, 'the schema of reviews.request@1'));

        return new AddonUi(
            new AddonNamespace('reviews'),
            $root,
            [
                new UiContribution(new ContributionId('reviews.summary'), PointKind::Slot, $card),
                new UiContribution(new ContributionId('reviews.badge'), PointKind::Slot, $card, data: $pending),
                new UiContribution(new ContributionId('reviews.queue'), PointKind::Page, $toolbar, data: $pending),
                new UiContribution(new ContributionId('reviews.submit'), PointKind::Decorator, $toolbar, tightens: ['tone_towards_danger', 'disabled_reason']),
                new UiContribution(new ContributionId('reviews.field'), PointKind::Replacement, $card),
                new UiContribution(new ContributionId('reviews.title-check'), PointKind::FormCheck, $toolbar, command: $create),
                new UiContribution(new ContributionId('reviews.four-eyes'), PointKind::FlowStep, $toolbar, command: $create, patches: ['ext.reviews.note', 'ext.reviews.approved_by']),
                new UiContribution(new ContributionId('reviews.audit'), PointKind::Observer, $toolbar),
                new UiContribution(new ContributionId('reviews.frame'), PointKind::Provider, $card),
            ],
            [$request, $create],
        );
    }
}
