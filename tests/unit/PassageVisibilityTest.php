<?php

namespace Ernestdefoe\Steward\Tests\unit;

use Ernestdefoe\Steward\Answers\Passage;
use Ernestdefoe\Steward\Answers\PassageVisibility;
use Ernestdefoe\Steward\Answers\Retrieval;
use PHPUnit\Framework\TestCase;

/**
 * The pure halves of keeping restricted posts out of answers: finding which
 * post a passage is, and re-judging a retrieval once some passages are gone.
 */
class PassageVisibilityTest extends TestCase
{
    private function p(string $url, float $score = 0.9, ?int $postId = null): Passage
    {
        return new Passage('T', 'text', $url, $score, $postId);
    }

    public function test_a_post_id_from_the_index_wins(): void
    {
        $this->assertSame(['post' => 42, 'discussion' => null, 'number' => null], PassageVisibility::locate($this->p('/d/7-x/3', 0.9, 42)));
    }

    public function test_a_discussion_link_is_read_with_or_without_slug_number_or_host(): void
    {
        $this->assertSame(['post' => null, 'discussion' => 7, 'number' => 3], PassageVisibility::locate($this->p('/d/7-staff-notes/3')));
        $this->assertSame(['post' => null, 'discussion' => 7, 'number' => null], PassageVisibility::locate($this->p('https://forum.example/d/7-staff-notes')));
        $this->assertSame(['post' => null, 'discussion' => 7, 'number' => 12], PassageVisibility::locate($this->p('https://forum.example/d/7/12')));
    }

    public function test_something_that_is_not_a_forum_post_is_not_located(): void
    {
        $this->assertNull(PassageVisibility::locate($this->p('/kb/passkeys')));
        $this->assertNull(PassageVisibility::locate($this->p('https://docs.example/d-and-d')));
    }

    public function test_dropping_the_strong_match_leaves_a_weak_remainder_unanswerable(): void
    {
        $r = Retrieval::from([$this->p('/d/1-staff/1', 0.9), $this->p('/d/2-public/1', 0.4)], 0.62);
        $this->assertTrue($r->usable);

        $narrowed = $r->only(fn (Passage $p) => $p->url !== '/d/1-staff/1');

        $this->assertFalse($narrowed->usable);
        $this->assertCount(1, $narrowed->passages);
        $this->assertSame('/d/2-public/1', $narrowed->passages[0]->url);
    }

    public function test_hosted_retrieval_is_left_for_the_relay(): void
    {
        $r = Retrieval::deferred();
        $this->assertSame($r, $r->only(fn () => false));
    }
}
