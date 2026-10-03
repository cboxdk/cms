<?php

declare(strict_types=1);

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;

// Every panel page behind the login sends the contributions active for the viewer as the prop
// cms.contributions, a document of packages/panel/resources/schemas/pages/contributions.v1.json:
// per point the page renders, the fills in render order, each with the point's props as the
// point's codec wrote them for it and whether its data comes as the deferred prop ext.<addon>.

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
    'a page with no active contribution' => ['{"points":[]}'],
    'a section that reads its data' => ['{"points":[{"fills":[{"addon":"approvals","data":true,"id":"approvals.badge","kind":"slot","priority":1000,"props":{"note":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01"}}],"point":"reviews.detail.sections@1"}]}'],
]);

it('refuses a fill that does not say whether it reads data', function (): void {
    expect(contributionsErrors('{"points":[{"fills":[{"addon":"approvals","id":"approvals.badge","kind":"slot","priority":1000,"props":{}}],"point":"reviews.detail.sections@1"}]}'))
        ->not->toBe([]);
});
