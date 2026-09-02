<?php

namespace Ernestdefoe\Steward\Answers;

use Ernestdefoe\Steward\Relay\RelayClient;
use Ernestdefoe\Steward\Relay\RelayException;

/**
 * Turns a question plus retrieved passages into an answer, or into an honest
 * refusal.
 *
 * Two guards, both put there by the benchmark rather than by taste:
 *
 *  1. A weak match is not answered at all. "banana helicopter tuesday" matched
 *     a real document at 0.576 against a genuine-question floor of 0.659.
 *
 *  2. The model is told it may say it does not know — and that instruction is
 *     not optional, because a threshold cannot catch the worse failure. Asked
 *     "the forum is running slowly", retrieval returned privacy documentation
 *     at 0.748, comfortably ABOVE any threshold. There was no performance page
 *     in the corpus, so it confidently returned the nearest thing. Only the
 *     model reading the passages can notice they do not answer the question.
 */
class Answerer
{
    public function __construct(
        private RelayClient $relay,
        private float $threshold = 0.62,
    ) {
    }

    public function answer(string $question, Retrieval $retrieval): Answer
    {
        if (! $retrieval->usable) {
            /*
             * Nothing close enough. Say so rather than spending a model call to
             * dress up a bad match — this is the cheap half of not being wrong.
             */
            return Answer::dontKnow($retrieval->topScore);
        }

        try {
            $res = $this->relay->post('v1/answer', [
                'question' => mb_substr($question, 0, 2000),
                'passages' => array_map(fn (Passage $p) => [
                    'title' => $p->title,
                    'text'  => mb_substr($p->text, 0, 2000),
                    'url'   => $p->url,
                ], $retrieval->passages),
            ]);
        } catch (RelayException $e) {
            return Answer::unavailable($e->exhausted);
        }

        $text = trim((string) ($res['data']['answer'] ?? ''));

        /*
         * 🚨 The relay is expected to return `answered: false` when the model
         * decides the passages do not cover the question. Treat a missing flag
         * as answered, but an empty body as not — a blank answer rendered as a
         * reply is worse than admitting the gap.
         */
        $answered = (bool) ($res['data']['answered'] ?? true);

        if (! $answered || $text === '') {
            return Answer::dontKnow($retrieval->topScore);
        }

        return Answer::found($text, $retrieval->passages);
    }
}
