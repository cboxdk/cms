<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions;

use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Visiting DeskWorld's test page in a test case of the Postgres suite: the page as the host first
 * loads it, its cms.contributions, the fills it lists, and the partial reload of the addon's
 * deferred data.
 */
trait VisitsDesk
{
    private ?DeskWorld $desk = null;

    protected function desk(): DeskWorld
    {
        return $this->desk ?? throw new LogicException('The desk world is not set up.');
    }

    /**
     * @return TestResponse<Response>
     */
    protected function visitDesk(TransportCredential $credential): TestResponse
    {
        return $this->withHeaders(DeskWorld::headers($credential))->get(DeskWorld::PATH)->assertOk();
    }

    /**
     * The partial reload of ext.tally, with the note desk.cards renders.
     *
     * @return TestResponse<Response>
     */
    protected function deskData(TransportCredential $credential, string $note = DeskWorld::NOTE): TestResponse
    {
        return $this->withHeaders(DeskWorld::headers($credential, ['ext.tally']))->get(DeskWorld::PATH.'?note='.$note)->assertOk();
    }

    /**
     * The page's cms.contributions, as the browser receives it.
     *
     * @param  TestResponse<Response>  $response
     * @return array<array-key, mixed>
     */
    protected static function contributionsOf(TestResponse $response): array
    {
        $contributions = $response->json('props.'.ContributionProps::CMS.'.'.ContributionProps::CONTRIBUTIONS);

        return is_array($contributions) ? $contributions : [];
    }

    /**
     * The fills of the page's first point.
     *
     * @param  TestResponse<Response>  $response
     * @return array<array-key, mixed>
     */
    protected static function firstFills(TestResponse $response): array
    {
        $points = self::contributionsOf($response)['points'] ?? null;
        $first = is_array($points) ? $points[0] ?? null : null;
        $fills = is_array($first) ? $first['fills'] ?? null : null;

        return is_array($fills) ? $fills : [];
    }

    /**
     * The ids of the fills of each point the page lists.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, list<string>>
     */
    protected static function listedFills(TestResponse $response): array
    {
        $listed = [];
        $points = self::contributionsOf($response)['points'] ?? null;

        foreach (is_array($points) ? $points : [] as $point) {
            if (is_array($point) && is_string($point['point'] ?? null) && is_array($point['fills'] ?? null)) {
                $listed[$point['point']] = array_values(array_map(static fn (mixed $fill): string => is_array($fill) && is_string($fill['id'] ?? null) ? $fill['id'] : '', $point['fills']));
            }
        }

        return $listed;
    }

    /**
     * The deferred prop ext.tally, by contribution id.
     *
     * @param  TestResponse<Response>  $page
     * @return array<array-key, mixed>
     */
    protected static function dataOf(TestResponse $page): array
    {
        $data = $page->json('props.ext.tally');

        return is_array($data) ? $data : [];
    }
}
