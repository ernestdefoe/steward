<?php

namespace Ernestdefoe\Steward\Tests\unit;

use Ernestdefoe\Steward\Moderation\PreFilter;
use Ernestdefoe\Steward\Moderation\Verdict;
use PHPUnit\Framework\TestCase;

class PreFilterTest extends TestCase
{
    private PreFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new PreFilter();
    }

    private function author(int $posts = 0, int $ageDays = 1, bool $mod = false): array
    {
        return ['postCount' => $posts, 'accountAgeDays' => $ageDays, 'isModerator' => $mod];
    }

    /** Established members cost nothing — this is most of the saving. */
    public function testTrustedMemberIsNeverScreened(): void
    {
        $spam = 'BUY NOW https://a.example https://b.example https://c.example';
        $v = $this->filter->screen($spam, $this->author(posts: 500, ageDays: 900));

        $this->assertSame(Verdict::CLEAR, $v->outcome);
        $this->assertFalse($v->costsMoney());
    }

    public function testModeratorIsNeverScreened(): void
    {
        $v = $this->filter->screen('SHOUTING ABOUT THE RULES AGAIN', $this->author(posts: 0, ageDays: 0, mod: true));
        $this->assertSame(Verdict::CLEAR, $v->outcome);
    }

    /** An ordinary post from a newcomer must not cost anything either. */
    public function testOrdinaryNewMemberPostIsClear(): void
    {
        $body = 'Thanks for the reply. I tried clearing the cache and it worked, '
              . 'so I think the issue was the stale assets rather than the config.';

        $v = $this->filter->screen($body, $this->author(posts: 1, ageDays: 2));
        $this->assertSame(Verdict::CLEAR, $v->outcome, 'a normal post must not be escalated');
    }

    /** Several signals agreeing is the only thing allowed to flag outright. */
    public function testObviousSpamIsFlaggedWithoutAModelCall(): void
    {
        $body = 'CHEAP DEALS https://x.example https://y.example https://z.example whatsapp +12345678901';
        $v = $this->filter->screen($body, $this->author(posts: 0, ageDays: 0));

        $this->assertSame(Verdict::FLAG, $v->outcome);
        $this->assertFalse($v->costsMoney(), 'a confident flag must not also pay for a model call');
        $this->assertGreaterThanOrEqual(2, count($v->reasons));
    }

    /** One signal is never enough to accuse someone. */
    public function testSingleSignalEscalatesRatherThanFlags(): void
    {
        // A newcomer sharing one link has a perfectly innocent explanation.
        $body = 'Here is the repo I mentioned: https://github.com/example/thing — hope it helps.';
        $v = $this->filter->screen($body, $this->author(posts: 0, ageDays: 1));

        $this->assertSame(Verdict::ESCALATE, $v->outcome);
        $this->assertTrue($v->costsMoney());
    }

    public function testEveryNonClearVerdictCarriesReasons(): void
    {
        foreach ([
            'CHEAP DEALS https://x.example https://y.example https://z.example whatsapp +12345678901',
            'Here is the repo: https://github.com/example/thing',
            'buybuybuybuybuybuybuybuybuy',
        ] as $body) {
            $v = $this->filter->screen($body, $this->author());
            if ($v->outcome !== Verdict::CLEAR) {
                $this->assertNotEmpty($v->reasons, "no reason given for: $body");
            }
        }
    }

    /**
     * 🚨 The economic assertion.
     *
     * The product only works if the large majority of real posts never reach a
     * model. This corpus is deliberately ordinary — the traffic a forum
     * actually carries — and a regression that starts escalating it would not
     * break any other test, it would just quietly multiply the bill.
     */
    public function testOrdinaryTrafficMostlyCostsNothing(): void
    {
        $corpus = [
            ['Has anyone got this working on PHP 8.4? I keep getting a segfault on boot.', 3, 40],
            ['Fixed it — the cache directory was owned by root. chown sorted it.', 12, 200],
            ['Welcome aboard! Have a look at the getting started guide in the sidebar.', 400, 900],
            ['I disagree, the second approach scales better once you pass a few thousand rows.', 60, 300],
            ['Bumping this, still seeing the issue on rc8.', 8, 30],
            ['Could you paste the full stack trace? Hard to say without it.', 150, 500],
            ['Done, thanks!', 45, 120],
            ['That worked perfectly, appreciate the quick response.', 2, 15],
            ['The docs say to run migrate first but that errors if the table exists already.', 5, 9],
            ['Great extension, been using it for months without a problem.', 30, 400],
        ];

        $escalated = 0;
        foreach ($corpus as [$body, $posts, $age]) {
            $v = $this->filter->screen($body, $this->author(posts: $posts, ageDays: $age));
            if ($v->costsMoney()) $escalated++;
        }

        $rate = $escalated / count($corpus);
        $this->assertLessThanOrEqual(
            0.2,
            $rate,
            sprintf('escalation rate %.0f%% is too high — every point here is real money', $rate * 100)
        );
    }
}
