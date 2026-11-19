<?php

namespace Ernestdefoe\Steward\Tests\integration\listener;

use Carbon\Carbon;
use Ernestdefoe\Steward\Job\IndexPosts;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Tags\Event\DiscussionWasTagged;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use PHPUnit\Framework\Attributes\Test;

class RequeueTest extends TestCase
{
    /** @var list<IndexPosts> */
    public static array $pushed = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$pushed = [];

        $this->prepareDatabase([
            Discussion::class => [['id' => 1, 'title' => 'Public', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 2]],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>One</p></t>'],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Two</p></t>'],
                ['id' => 3, 'discussion_id' => 1, 'number' => 3, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'discussionRenamed', 'content' => '["a","b"]'],
            ],
        ]);
    }

    private function retag(): void
    {
        $this->app()->getContainer()->instance(Queue::class, new class extends \Illuminate\Queue\NullQueue {
            public function push($job, $data = '', $queue = null)
            {
                RequeueTest::$pushed[] = $job;
            }
        });

        $this->app()->getContainer()->make(Dispatcher::class)
            ->dispatch(new DiscussionWasTagged(Discussion::query()->find(1), User::query()->find(1), []));
    }

    #[Test]
    public function retagging_requeues_the_discussions_comments_in_one_batch()
    {
        $this->extension('flarum-tags', 'ernestdefoe-steward');
        $this->retag();

        $this->assertCount(1, self::$pushed, 'One job for the discussion, not one per post');
        $this->assertSame([1, 2], self::$pushed[0]->postIds, 'Comments only');
        $this->assertFalse(self::$pushed[0]->withdraw);
    }

    #[Test]
    public function a_forum_on_its_own_opensearch_sends_no_posts_anywhere()
    {
        $this->extension('flarum-tags', 'ernestdefoe-steward');
        $this->setting('steward.opensearch_url', 'https://search.invalid');
        $this->retag();

        $this->assertSame([], self::$pushed);
    }

    #[Test]
    public function with_answers_off_a_retagged_discussion_is_still_rechecked_for_withdrawal()
    {
        $this->extension('flarum-tags', 'ernestdefoe-steward');
        $this->setting('steward.answers', '0');
        $this->retag();

        $this->assertCount(1, self::$pushed, 'Queued, so posts that stopped being public are withdrawn');
    }

    #[Test]
    public function without_tags_enabled_nothing_listens()
    {
        $this->extension('ernestdefoe-steward');
        $this->retag();

        $this->assertSame([], self::$pushed);
    }
}
