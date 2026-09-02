<?php

namespace Ernestdefoe\Steward\Tests\unit;

use Ernestdefoe\Steward\Moderation\Decision;
use Ernestdefoe\Steward\Moderation\PreFilter;
use Ernestdefoe\Steward\Moderation\Screener;
use Ernestdefoe\Steward\Relay\RelayClient;
use Ernestdefoe\Steward\Relay\RelayException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ScreenerTest extends TestCase
{
    private function relayReturning(array $data): RelayClient
    {
        $stub = new class extends RelayClient {
            public array $data = [];
            public int $calls = 0;
            public function __construct() {}
            public function configured(): bool { return true; }
            public function post(string $path, array $payload): array
            {
                $this->calls++;
                return ['data' => $this->data, 'quota' => new \Ernestdefoe\Steward\Relay\Quota()];
            }
        };
        $stub->data = $data;

        return $stub;
    }

    private function relayThrowing(RelayException $e): RelayClient
    {
        $stub = new class extends RelayClient {
            public ?RelayException $boom = null;
            public int $calls = 0;
            public function __construct() {}
            public function configured(): bool { return true; }
            public function post(string $path, array $payload): array
            {
                $this->calls++;
                throw $this->boom;
            }
        };
        $stub->boom = $e;

        return $stub;
    }

    private function screener(RelayClient $relay): Screener
    {
        return new Screener(new PreFilter(), $relay, new NullLogger());
    }

    private function author(int $posts = 0, int $age = 1, bool $mod = false): array
    {
        return ['postCount' => $posts, 'accountAgeDays' => $age, 'isModerator' => $mod];
    }

    /** The saving: a clear post must never reach the relay at all. */
    public function testClearPostNeverCallsTheRelay(): void
    {
        $relay = $this->relayReturning(['verdict' => 'remove']);
        $d = $this->screener($relay)->screen(
            'Thanks, that fixed it — the cache directory was the problem.',
            $this->author(posts: 300, age: 500)
        );

        $this->assertSame(Decision::ALLOW, $d->action);
        $this->assertSame(0, $relay->calls, 'a cleared post must not cost a model call');
    }

    /** A confident pre-filter flag also skips the model. */
    public function testConfidentPreFilterFlagSkipsTheRelay(): void
    {
        $relay = $this->relayReturning(['verdict' => 'allow']);
        $d = $this->screener($relay)->screen(
            'CHEAP DEALS https://x.example https://y.example https://z.example whatsapp +12345678901',
            $this->author()
        );

        $this->assertSame(Decision::REVIEW, $d->action);
        $this->assertSame('pre-filter', $d->source);
        $this->assertSame(0, $relay->calls);
    }

    public function testAmbiguousPostIsSentToTheModel(): void
    {
        $relay = $this->relayReturning(['verdict' => 'spam', 'reason' => 'promotional link from a new account']);
        $d = $this->screener($relay)->screen(
            'Have a look at this: https://example.com/thing',
            $this->author()
        );

        $this->assertSame(1, $relay->calls);
        $this->assertSame(Decision::REVIEW, $d->action);
        $this->assertSame(['promotional link from a new account'], $d->reasons);
    }

    /**
     * 🚨 The most important test here.
     *
     * When the allowance runs out or the relay is down, posts must go THROUGH.
     * Holding everyone's posts because a billing period ended turns an outage
     * into a forum that appears broken to every member at once.
     */
    public function testRunningOutOfAllowanceAllowsThePostAndSaysSo(): void
    {
        $relay = $this->relayThrowing(new RelayException('out', exhausted: true));

        $d = $this->screener($relay)->screen(
            'Have a look at this: https://example.com/thing',
            $this->author()
        );

        $this->assertSame(Decision::ALLOW, $d->action);
        $this->assertTrue($d->unscreened, 'the post must be recorded as NOT screened');
        $this->assertStringContainsString('allowance', $d->reasons[0]);
    }

    public function testRelayOutageAlsoAllowsThePost(): void
    {
        $relay = $this->relayThrowing(new RelayException('down', retryable: true));

        $d = $this->screener($relay)->screen(
            'Have a look at this: https://example.com/thing',
            $this->author()
        );

        $this->assertSame(Decision::ALLOW, $d->action);
        $this->assertTrue($d->unscreened);
    }

    /** CSAM is handed to Guardian, never judged here. */
    public function testCsamEscalatesToGuardian(): void
    {
        $relay = $this->relayReturning(['verdict' => 'csam', 'reason' => 'child safety signal']);
        $d = $this->screener($relay)->screen(
            'Have a look at this: https://example.com/thing',
            $this->author()
        );

        $this->assertSame(Decision::GUARDIAN, $d->action);
        $this->assertTrue($d->needsAHuman());
    }

    /** Nothing Steward decides may remove a post outright. */
    public function testNoVerdictEverRemovesAPost(): void
    {
        foreach (['remove', 'reject', 'spam', 'review', 'unsure', 'allow', 'nonsense'] as $v) {
            $relay = $this->relayReturning(['verdict' => $v]);
            $d = $this->screener($relay)->screen(
                'Have a look at this: https://example.com/thing',
                $this->author()
            );
            $this->assertContains(
                $d->action,
                [Decision::ALLOW, Decision::REVIEW, Decision::GUARDIAN],
                "verdict '$v' produced an action outside the allowed set"
            );
        }
    }
}
