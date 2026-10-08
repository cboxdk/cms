<?php

declare(strict_types=1);

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;

// Every panel page behind the login sends the contributions active for the viewer as the prop
// cms.contributions, a document of packages/panel/resources/schemas/pages/contributions.v1.json:
// per point the page renders, its kind and multiplicity and the fills in render order, each with
// the point's props as the point's codec wrote them for it, whether its data comes as the deferred
// prop ext.<addon> and what its kind needs besides; the registration of each addon whose code runs
// on the page; the texts of the active locale for each of those addons, which the host serves a
// contribution's t() from; what the host needs to navigate and run commands; and the id of the
// viewer the contributions were resolved for, a UUIDv7, which a form check's context names.

/**
 * The errors of a cms.contributions document, none when it is valid.
 *
 * @return array<array-key, mixed>
 */
function contributionsErrors(string $document): array
{
    $json = file_get_contents(dirname(__DIR__, 3).'/packages/panel/resources/schemas/pages/contributions.v1.json')
        ?: throw new RuntimeException('Cannot read contributions.v1.json.');
    $error = new CompliantValidator()->validate(json_decode($document), $json)->error();

    return $error instanceof ValidationError ? new ErrorFormatter()->format($error) : [];
}

it('accepts the contributions of a page', function (string $document): void {
    expect(contributionsErrors($document))->toBe([]);
})->with([
    'a page with no active contribution' => ['{"addons":[],"commands":"/cms/commands","details":false,"pages":[{"page":"home","url":"/cms"}],"points":[],"texts":[],"viewer":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02"}'],
    'a section that reads its data' => ['{"addons":[{"addon":"approvals","any_command":false,"issues":["approvals.request@1"],"registration":"0000000000000000000000000000000000000000000000000000000000000000"}],"commands":"/cms/commands","details":false,"pages":[{"page":"home","url":"/cms"}],"points":[{"fills":[{"action":null,"addon":"approvals","check":null,"data":true,"decorator":null,"id":"approvals.badge","kind":"slot","nav":null,"priority":1000,"props":{"note":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01"},"replacement":null,"step":null}],"kind":"slot","max":null,"multiplicity":"many","point":"reviews.detail.sections@1","region":"sections"}],"texts":[{"addon":"approvals","entries":[{"key":"approvals.badge.title","text":"Badge"}]}],"viewer":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02"}'],
    'the host before the server sent any contributions' => ['{"addons":[],"commands":"/cms/commands","details":false,"pages":[],"points":[],"texts":[],"viewer":null}'],
]);

it('refuses a fill that does not say whether it reads data', function (): void {
    expect(contributionsErrors('{"addons":[{"addon":"approvals","any_command":false,"issues":["approvals.request@1"],"registration":"0000000000000000000000000000000000000000000000000000000000000000"}],"commands":"/cms/commands","details":false,"pages":[{"page":"home","url":"/cms"}],"points":[{"fills":[{"action":null,"addon":"approvals","check":null,"decorator":null,"id":"approvals.badge","kind":"slot","nav":null,"priority":1000,"props":{"note":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01"},"replacement":null,"step":null}],"kind":"slot","max":null,"multiplicity":"many","point":"reviews.detail.sections@1","region":"sections"}],"texts":[{"addon":"approvals","entries":[{"key":"approvals.badge.title","text":"Badge"}]}],"viewer":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02"}'))
        ->not->toBe([]);
});

it('refuses a viewer that is not a lowercase UUIDv7', function (): void {
    expect(contributionsErrors('{"addons":[],"commands":"/cms/commands","details":false,"pages":[{"page":"home","url":"/cms"}],"points":[],"texts":[],"viewer":"ada@example.com"}'))
        ->not->toBe([]);
});
