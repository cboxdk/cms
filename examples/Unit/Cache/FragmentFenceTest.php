<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cache\Fragment;
use Cbox\Cms\Contracts\Cache\FragmentFenced;
use Cbox\Cms\Contracts\Cache\FragmentKey;
use Cbox\Cms\Contracts\Cache\FragmentPurge;
use Cbox\Cms\Contracts\Cache\FragmentStored;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Testkit\Cache\FakeFragmentStore;
use Cbox\Cms\Testkit\Clock\FakeClock;

// The fragment store on the testkit's fake. A front page depends on the article it shows. The
// article is revised by the changeset at position 1200, and the purge of its key fences the store:
// a render that read before that commit is refused, one that read after it is kept.

it('refuses a render that read before the purge and keeps one that read after it', function (): void {
    $clock = new FakeClock;
    $store = new FakeFragmentStore($clock);
    $article = DependencyKey::entry(EntryId::fromString('01960000-0000-7000-8000-00000000000a'));
    $front = new FragmentKey('www.example.com/');
    $render = fn (string $readAt): Fragment => new Fragment($front, '<main>...</main>', [$article], new CommitPosition($readAt), $clock->now()->modify('+5 minutes'));

    $store->write($render('1100'));
    $purged = $store->purge(new FragmentPurge($article, new CommitPosition('1200'), $clock->now()->modify('+2 minutes')));

    // A render that started before the commit, or read a replica that lags, arrives late.
    $late = $store->write($render('1150'));
    // The next render read a snapshot whose xmin is past the commit, so it saw the revision.
    $fresh = $store->write($render('1201'));

    expect($purged)->toEqual([$front])
        ->and($late)->toBeInstanceOf(FragmentFenced::class)
        ->and($late instanceof FragmentFenced ? $late->purgedAt->value : null)->toBe('1200')
        ->and($fresh)->toBeInstanceOf(FragmentStored::class)
        ->and($store->read($front)?->builtAt->value)->toBe('1201')
        ->and($store->fragmentsOf($article))->toEqual([$front]);
});

it('forgets a fragment once the clock reaches its validUntil', function (): void {
    $clock = new FakeClock;
    $store = new FakeFragmentStore($clock);
    $article = DependencyKey::entry(EntryId::fromString('01960000-0000-7000-8000-00000000000a'));
    $key = new FragmentKey('www.example.com/news/1');

    $store->write(new Fragment($key, '<article>...</article>', [$article], new CommitPosition('1300'), $clock->now()->modify('+30 seconds')));
    $clock->advance(new DateInterval('PT30S'));

    expect($store->read($key))->toBeNull()
        ->and($store->fragmentsOf($article))->toBe([]);
});
