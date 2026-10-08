<?php

namespace Ernestdefoe\Steward\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class ReviewsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-steward');

        $users = [$this->normalUser() + ['joined_at' => Carbon::now()]];
        $users[] = ['id' => 3, 'username' => 'moderator', 'email' => 'mod@machine.local', 'password' => 'too-obscure', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()->subYear()];
        foreach (range(10, 16) as $id) {
            $users[] = ['id' => $id, 'username' => "author$id", 'email' => "a$id@machine.local", 'password' => 'too-obscure', 'is_email_confirmed' => 1];
        }

        $posts = [];
        $reviews = [];
        foreach (range(10, 16) as $n => $id) {
            $posts[] = ['id' => $id, 'discussion_id' => 1, 'number' => $n + 2, 'created_at' => Carbon::now(), 'user_id' => $id, 'type' => 'comment', 'content' => "<t><p>Post by $id</p></t>"];
            $reviews[] = ['id' => $n + 1, 'post_id' => $id, 'user_id' => $id, 'action' => 'review', 'source' => 'model', 'reasons' => '["reads like spam"]', 'confidence' => 0.8, 'unscreened' => false, 'created_at' => Carbon::now()->subMinutes($n)];
        }
        // Review 6 went through unchecked; review 7 is already resolved.
        $reviews[5] = ['unscreened' => true, 'action' => 'allow', 'reasons' => '["screening unavailable — not screened"]'] + $reviews[5];
        $reviews[6] = ['resolution' => 'kept', 'resolved_by' => 1, 'resolved_at' => Carbon::now()] + $reviews[6];

        $this->prepareDatabase([
            User::class => $users,
            'group_user' => [['user_id' => 3, 'group_id' => Group::MODERATOR_ID]],
            Discussion::class => [['id' => 1, 'title' => 'Welcome', 'slug' => 'welcome', 'created_at' => Carbon::now(), 'user_id' => 3, 'first_post_id' => 1, 'comment_count' => 8, 'last_post_number' => 8]],
            Post::class => array_merge([['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now()->subHour(), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Hi</p></t>']], $posts),
            'steward_reviews' => $reviews,
        ]);
    }

    private function queue(int $actor, string $filter = 'open'): array
    {
        $response = $this->send($this->request('GET', '/api/steward/reviews', ['authenticatedAs' => $actor])->withQueryParams(['filter' => $filter]));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function resolve(int $actor, int $review, string $resolution): int
    {
        return $this->send($this->request('POST', "/api/steward/reviews/$review/resolve", ['authenticatedAs' => $actor, 'json' => ['resolution' => $resolution]]))->getStatusCode();
    }

    #[Test]
    public function the_queue_is_for_moderators_only()
    {
        $this->assertSame(403, $this->queue(2)[0]);
        $this->assertSame(403, $this->send($this->request('GET', '/api/steward/reviews'))->getStatusCode(), 'A guest');

        [$status] = $this->queue(3);
        $this->assertSame(200, $status);
    }

    #[Test]
    public function open_unscreened_and_resolved_are_kept_apart()
    {
        [, $open] = $this->queue(3);
        $this->assertSame([1, 2, 3, 4, 5], array_column($open['reviews'], 'id'), 'Newest first; unchecked and resolved left out');
        $this->assertSame(['open' => 5, 'unscreened' => 1], $open['counts']);

        $this->assertSame([6], array_column($this->queue(3, 'unscreened')[1]['reviews'], 'id'));
        $this->assertSame([7], array_column($this->queue(3, 'resolved')[1]['reviews'], 'id'));
    }

    #[Test]
    public function a_queued_review_shows_its_post_and_author_without_a_query_per_row()
    {
        // Five open reviews by five authors in one request: flarum/testing
        // fails it on a query repeated per review.
        [, $open] = $this->queue(3);
        $first = $open['reviews'][0];

        $this->assertSame(10, $first['postId']);
        $this->assertSame('author10', $first['author']);
        $this->assertSame('Post by 10', $first['excerpt']);
        $this->assertSame('/d/1-welcome/2', $first['url']);
        $this->assertSame(['reads like spam'], $first['reasons']);
    }

    #[Test]
    public function only_a_moderator_resolves_and_only_with_a_known_outcome()
    {
        $this->assertSame(403, $this->resolve(2, 1, 'kept'));
        $this->assertSame(422, $this->resolve(3, 1, 'deleted'));
        $this->assertSame(404, $this->resolve(3, 999, 'kept'));

        $this->assertSame(200, $this->resolve(3, 1, 'removed'));
        $row = $this->database()->table('steward_reviews')->where('id', 1)->first();
        $this->assertSame('removed', $row->resolution);
        $this->assertEquals(3, $row->resolved_by);
        $this->assertNull($this->database()->table('posts')->where('id', 10)->value('hidden_at'), 'Resolving records a decision; it does not touch the post');
    }

    #[Test]
    public function usage_is_for_admins_and_an_unconnected_forum_says_so()
    {
        $this->assertSame(403, $this->send($this->request('GET', '/api/steward/usage', ['authenticatedAs' => 3]))->getStatusCode());

        $response = $this->send($this->request('GET', '/api/steward/usage', ['authenticatedAs' => 1]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['connected' => false], json_decode((string) $response->getBody(), true));
    }

    private function reply(int $actor, string $content): int
    {
        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => $actor,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => $content], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) json_decode((string) $response->getBody(), true)['data']['id'];
    }

    #[Test]
    public function a_new_account_posting_bare_links_is_queued_by_the_pre_filter()
    {
        $post = $this->reply(2, 'https://spam.test/a https://spam.test/b https://spam.test/c');

        $review = $this->database()->table('steward_reviews')->where('post_id', $post)->first();
        $this->assertNotNull($review);
        $this->assertSame('pre-filter', $review->source);
        $this->assertFalse((bool) $review->unscreened);
    }

    #[Test]
    public function a_post_the_model_could_not_read_is_recorded_as_unscreened()
    {
        // Not damning enough for the pre-filter alone, and no relay to ask.
        $post = $this->reply(2, 'Check out https://shop.test for great deals on watches today');

        $review = $this->database()->table('steward_reviews')->where('post_id', $post)->first();
        $this->assertNotNull($review, 'Recorded, not silently allowed');
        $this->assertTrue((bool) $review->unscreened);
    }

    #[Test]
    public function a_moderators_post_is_not_screened()
    {
        $post = $this->reply(3, 'https://a.test https://b.test https://c.test');
        $this->assertSame(0, $this->database()->table('steward_reviews')->where('post_id', $post)->count());
    }

    #[Test]
    public function nothing_is_screened_with_moderation_switched_off()
    {
        $this->setting('steward.moderation', '0');

        $post = $this->reply(2, 'https://spam.test/a https://spam.test/b https://spam.test/c');
        $this->assertSame(0, $this->database()->table('steward_reviews')->where('post_id', $post)->count());
    }
}
