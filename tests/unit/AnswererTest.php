<?php

namespace Ernestdefoe\Steward\Tests\unit;

use Ernestdefoe\Steward\Answers\Answerer;
use Ernestdefoe\Steward\Answers\Passage;
use Ernestdefoe\Steward\Answers\Retrieval;
use Ernestdefoe\Steward\Relay\Quota;
use Ernestdefoe\Steward\Relay\RelayClient;
use Ernestdefoe\Steward\Relay\RelayException;
use PHPUnit\Framework\TestCase;

class AnswererTest extends TestCase
{
    private function relay(array $data = [], ?RelayException $boom = null): RelayClient
    {
        $stub = new class extends RelayClient {
            public array $data = [];
            public ?RelayException $boom = null;
            public int $calls = 0;
            public function __construct() {}
            public function configured(): bool { return true; }
            public function post(string $path, array $payload): array
            {
                $this->calls++;
                if ($this->boom) throw $this->boom;
                return ['data' => $this->data, 'quota' => new Quota()];
            }
        };
        $stub->data = $data;
        $stub->boom = $boom;

        return $stub;
    }

    private function passages(float $score): array
    {
        return [new Passage('Passkeys', 'How to set up a passkey.', '/kb/passkeys', $score)];
    }

    /**
     * 🚨 The measured case. "banana helicopter tuesday" matched a real document
     * at 0.576 on the live index; genuine questions floored at 0.659.
     */
    public function testWeakMatchIsNotAnsweredAndCostsNothing(): void
    {
        $relay = $this->relay(['answer' => 'Here is how to do that!']);
        $a = (new Answerer($relay, threshold: 0.62))
            ->answer('banana helicopter tuesday', Retrieval::from($this->passages(0.576), 0.62));

        $this->assertFalse($a->answered);
        $this->assertSame(0, $relay->calls, 'a weak match must not pay for a model call');
    }

    public function testGoodMatchIsAnswered(): void
    {
        $relay = $this->relay(['answer' => 'Open settings and add a passkey.']);
        $a = (new Answerer($relay, threshold: 0.62))
            ->answer('how do I change my password', Retrieval::from($this->passages(0.666), 0.62));

        $this->assertTrue($a->answered);
        $this->assertSame(1, $relay->calls);
        $this->assertNotEmpty($a->sources);
    }

    /**
     * 🚨 The failure a threshold CANNOT catch. "the forum is running slowly"
     * scored 0.748 — above any threshold — and returned privacy docs, because
     * the corpus had no performance page. Only the model reading the passages
     * can notice they do not answer the question.
     */
    public function testModelMaySayItDoesNotKnowEvenOnAStrongMatch(): void
    {
        $relay = $this->relay(['answered' => false, 'answer' => '']);
        $a = (new Answerer($relay, threshold: 0.62))
            ->answer('the forum is running slowly', Retrieval::from($this->passages(0.748), 0.62));

        $this->assertFalse($a->answered, 'a high score must not force an answer');
        $this->assertSame(1, $relay->calls);
    }

    public function testEmptyAnswerIsTreatedAsNotKnowing(): void
    {
        $relay = $this->relay(['answered' => true, 'answer' => '   ']);
        $a = (new Answerer($relay, threshold: 0.62))
            ->answer('anything', Retrieval::from($this->passages(0.9), 0.62));

        $this->assertFalse($a->answered);
    }

    public function testNothingRetrievedIsNotAnswered(): void
    {
        $relay = $this->relay(['answer' => 'made up']);
        $a = (new Answerer($relay))->answer('anything', Retrieval::nothing());

        $this->assertFalse($a->answered);
        $this->assertSame(0, $relay->calls);
    }

    public function testExhaustedAllowanceIsDistinguishableFromAnOutage(): void
    {
        $out  = (new Answerer($this->relay([], new RelayException('x', exhausted: true))))
            ->answer('q', Retrieval::from($this->passages(0.9), 0.62));
        $down = (new Answerer($this->relay([], new RelayException('x', retryable: true))))
            ->answer('q', Retrieval::from($this->passages(0.9), 0.62));

        $this->assertTrue($out->unavailable);
        $this->assertTrue($out->exhausted, 'running out must be tellable from breaking');
        $this->assertTrue($down->unavailable);
        $this->assertFalse($down->exhausted);
    }
}
