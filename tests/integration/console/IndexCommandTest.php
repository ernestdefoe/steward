<?php

namespace Ernestdefoe\Steward\Tests\integration\console;

use Carbon\Carbon;
use Ernestdefoe\Steward\Console\IndexCommand;
use Ernestdefoe\Steward\Job\IndexPost;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\ConsoleTestCase;
use Illuminate\Contracts\Queue\Queue;
use PHPUnit\Framework\Attributes\Test;

class IndexCommandTest extends ConsoleTestCase
{
    /** @var list<int> post ids queued for indexing */
    public static array $queued = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-steward');
        $this->setting('steward.site_key', 'test-key');
        $this->setting('steward.relay_url', 'https://relay.invalid');
        self::$queued = [];

        $this->prepareDatabase([
            Discussion::class => [
                ['id' => 1, 'title' => 'Public', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 2],
                ['id' => 2, 'title' => 'Hidden', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 3, 'comment_count' => 1, 'hidden_at' => Carbon::now()],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>One</p></t>'],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Two</p></t>'],
                ['id' => 3, 'discussion_id' => 2, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Secret</p></t>'],
                ['id' => 4, 'discussion_id' => 1, 'number' => 3, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'discussionRenamed', 'content' => '["a","b"]'],
                ['id' => 5, 'discussion_id' => 1, 'number' => 4, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Five</p></t>'],
            ],
        ]);
    }

    private function fakeQueue(): void
    {
        $this->app()->getContainer()->instance(Queue::class, new class extends \Illuminate\Queue\NullQueue {
            public function push($job, $data = '', $queue = null)
            {
                $id = (new \ReflectionProperty(IndexPost::class, 'postId'))->getValue($job);
                IndexCommandTest::$queued[] = $id;
            }
        });
    }

    #[Test]
    public function it_queues_every_public_post_that_existed_before_install()
    {
        $this->fakeQueue();

        $this->runCommand(['command' => 'steward:index', '--chunk' => 2]);

        $this->assertSame([1, 2, 5], self::$queued, 'Hidden discussions and event posts are left out');
        $this->assertSame('5', $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->get(IndexCommand::CURSOR));
    }

    #[Test]
    public function an_interrupted_run_resumes_after_the_last_post_done()
    {
        $this->setting(IndexCommand::CURSOR, '2');
        $this->fakeQueue();

        $this->runCommand(['command' => 'steward:index']);
        $this->assertSame([5], self::$queued);

        self::$queued = [];
        $this->runCommand(['command' => 'steward:index', '--restart' => true]);
        $this->assertSame([1, 2, 5], self::$queued);
    }

    #[Test]
    public function a_forum_on_its_own_opensearch_sends_nothing()
    {
        $this->setting('steward.opensearch_url', 'https://search.invalid');
        $this->fakeQueue();

        $this->assertStringContainsString('own OpenSearch', $this->runCommand(['command' => 'steward:index']));
        $this->assertSame([], self::$queued);
    }

    #[Test]
    public function an_event_post_is_never_sent_as_content()
    {
        $relay = new class extends \Ernestdefoe\Steward\Relay\RelayClient {
            public array $sent = [];

            public function __construct()
            {
            }

            public function configured(): bool
            {
                return true;
            }

            public function post(string $path, array $payload): array
            {
                $this->sent[] = $payload;

                return ['data' => []];
            }
        };

        $this->app();
        (new IndexPost(4))->handle($relay, new \Psr\Log\NullLogger());
        (new IndexPost(1))->handle($relay, new \Psr\Log\NullLogger());

        $this->assertSame(['postId' => 4, 'deleted' => true], $relay->sent[0], 'A rename is not indexed');
        $this->assertSame('One', trim($relay->sent[1]['body']));
    }
}
