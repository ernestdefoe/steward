<?php

namespace Ernestdefoe\Steward\Answers;

use Flarum\User\User;

/**
 * Answers one member's question: retrieval, the relay, and the citations.
 *
 * Runs on the queue (AnswerQuestion), so the outbound calls never hold a web
 * request open.
 */
class Asker
{
    public function __construct(
        private RetrievalProvider $retrieval,
        private Answerer $answerer,
        private PassageVisibility $visibility,
    ) {
    }

    /** @return array<string, mixed> what the member is shown */
    public function ask(string $question, User $actor): array
    {
        /*
         * 🚨 Only what this member could read on the forum is answered from or
         * cited. The passages are narrowed BEFORE they reach the model, so a
         * restricted thread can shape neither the answer nor the sources.
         * (Hosted retrieval holds only guest-visible posts; see Index\Indexer.)
         */
        $retrieval = $this->visibility->restrict($this->retrieval->find($question), $actor);
        $answer = $this->answerer->answer($question, $retrieval);

        /*
         * 🚨 Running out and breaking are told apart, and neither is dressed up
         * as an answer. A member sees a plain "I could not find that"; only an
         * admin needs to know which of the two it was.
         */
        if ($answer->unavailable) {
            return ['answered' => false, 'reason' => $answer->exhausted ? 'exhausted' : 'unavailable'];
        }

        if (! $answer->answered) {
            return ['answered' => false, 'reason' => 'not_found'];
        }

        $sources = $answer->sources;

        /*
         * Hosted sources arrive with the answer, so they are checked against
         * this member now. The hosted index holds only public posts, but a post
         * made private a moment ago may not have been withdrawn yet.
         */
        if ($retrieval->deferred && $sources !== []) {
            $sources = $this->visibility->restrict(Retrieval::from($sources, 0.0), $actor)->passages;
        }

        return [
            'answered' => true,
            'answer' => $answer->text,
            // Always cited, so a member can check the answer rather than trust it.
            'sources' => array_values(array_map(
                fn (Passage $p) => ['title' => $p->title, 'url' => $p->url],
                array_filter($sources, fn (Passage $p) => $p->linkable()),
            )),
        ];
    }
}
