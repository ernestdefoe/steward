<?php

namespace Ernestdefoe\Steward\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Steward\Answers\Answerer;
use Ernestdefoe\Steward\Answers\Passage;
use Ernestdefoe\Steward\Answers\Retrieval;
use Ernestdefoe\Steward\Answers\RetrievalProvider;
use Ernestdefoe\Steward\Relay\RelayClient;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class AskTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** @var list<array<string, mixed>> what the relay was sent */
    public static array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-steward');
        self::$sent = [];

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [
                ['id' => 1, 'title' => 'Passkeys', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1],
                ['id' => 2, 'title' => 'Staff only', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 2, 'comment_count' => 1, 'hidden_at' => Carbon::now()],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Add a passkey in settings.</p></t>'],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>The admin password is hunter2.</p></t>'],
            ],
        ]);
    }

    /** Retrieval that finds both posts, and a relay that answers from what it is given. */
    private function fakeServices(bool $answered = true): void
    {
        $container = $this->app()->getContainer();

        $container->instance(RetrievalProvider::class, new class implements RetrievalProvider {
            public function find(string $question, int $limit = 5): Retrieval
            {
                return Retrieval::from([
                    new Passage('Passkeys', 'Add a passkey in settings.', '/d/1-passkeys', 0.9, 1),
                    new Passage('Staff only', 'The admin password is hunter2.', '/d/2-staff-only/1', 0.95),
                ], 0.62);
            }

            public function available(): bool
            {
                return true;
            }
        });

        $relay = new class($answered) extends RelayClient {
            public function __construct(private bool $answered)
            {
            }

            public function configured(): bool
            {
                return true;
            }

            public function post(string $path, array $payload): array
            {
                AskTest::$sent[] = $payload;

                return ['data' => ['answer' => 'Use a passkey.', 'answered' => $this->answered]];
            }
        };
        $container->instance(Answerer::class, new Answerer($relay));
    }

    private bool $cacheCleared = false;

    private function ask(?int $actor, string $question = 'How do I sign in?'): array
    {
        // The ask throttle counts in the test forum's cache, which outlives a
        // test (and a run); start each test from zero.
        if (! $this->cacheCleared) {
            $this->app()->getContainer()->make(\Illuminate\Contracts\Cache\Repository::class)->flush();
            $this->cacheCleared = true;
        }

        $request = $this->request('POST', '/api/steward/ask', ['json' => ['question' => $question]] + ($actor ? ['authenticatedAs' => $actor] : []));
        if (! $actor) {
            $request = $this->requestWithCsrfToken($request);
        }
        $response = $this->send($request);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function a_guest_cannot_ask()
    {
        $this->assertSame(401, $this->ask(null)[0]);
    }

    #[Test]
    public function asking_needs_answers_switched_on_and_a_question()
    {
        $this->setting('steward.answers', '0');
        $this->assertSame(422, $this->ask(2)[0]);
    }

    #[Test]
    public function an_empty_question_is_refused()
    {
        $this->assertSame(422, $this->ask(2, '   ')[0]);
    }

    #[Test]
    public function an_unconnected_forum_says_answers_are_unavailable()
    {
        [$status, $body] = $this->ask(2);

        $this->assertSame(200, $status);
        $this->assertFalse($body['answered']);
        $this->assertSame('unavailable', $body['reason'], 'Not dressed up as "found nothing"');
    }

    #[Test]
    public function an_answer_cites_and_reads_only_what_the_member_may_read()
    {
        $this->fakeServices();

        [$status, $body] = $this->ask(2);

        $this->assertSame(200, $status);
        $this->assertTrue($body['answered']);
        $this->assertSame([['title' => 'Passkeys', 'url' => '/d/1-passkeys']], $body['sources'], 'The hidden discussion is not cited');
        $this->assertCount(1, self::$sent);
        $this->assertSame(['Passkeys'], array_column(self::$sent[0]['passages'], 'title'), 'Nor is it sent to the model');
    }

    #[Test]
    public function a_question_the_passages_do_not_cover_is_not_answered()
    {
        $this->fakeServices(answered: false);

        [$status, $body] = $this->ask(2);

        $this->assertSame(200, $status);
        $this->assertSame(['answered' => false, 'reason' => 'not_found'], $body);
    }

    #[Test]
    public function an_admin_who_can_see_a_hidden_discussion_is_answered_from_it()
    {
        $this->fakeServices();

        [, $body] = $this->ask(1);

        $this->assertSame(['Passkeys', 'Staff only'], array_column($body['sources'], 'title'));
    }

    #[Test]
    public function a_member_may_ask_six_times_a_minute()
    {
        $this->fakeServices();

        foreach (range(1, 6) as $n) {
            $this->assertSame(200, $this->ask(2)[0], "Ask $n");
        }
        $this->assertSame(429, $this->ask(2)[0]);
        $this->assertSame(200, $this->ask(1)[0], 'Someone else is not held back');
    }

    #[Test]
    public function the_forum_says_who_may_review_and_whether_answers_are_on()
    {
        $attributes = fn (?int $actor) => json_decode((string) $this->send($this->request('GET', '/api', $actor ? ['authenticatedAs' => $actor] : []))->getBody(), true)['data']['attributes'];

        $this->assertFalse($attributes(2)['canReviewSteward']);
        $this->assertTrue($attributes(1)['canReviewSteward']);
        $this->assertTrue($attributes(null)['stewardAnswers']);
    }
}
