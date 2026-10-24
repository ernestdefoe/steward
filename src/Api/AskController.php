<?php

namespace Ernestdefoe\Steward\Api;

use Ernestdefoe\Steward\Answers\Answerer;
use Ernestdefoe\Steward\Answers\Passage;
use Ernestdefoe\Steward\Answers\PassageVisibility;
use Ernestdefoe\Steward\Answers\Retrieval;
use Ernestdefoe\Steward\Answers\RetrievalProvider;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/steward/ask — a member asks something.
 */
class AskController implements RequestHandlerInterface
{
    public function __construct(
        private RetrievalProvider $retrieval,
        private Answerer $answerer,
        private SettingsRepositoryInterface $settings,
        private PassageVisibility $visibility,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        /*
         * 🚨 Registered members only, and not because of cost alone.
         *
         * Every answer is drawn from this forum's own content, so answering a
         * signed-out stranger is a way to read the forum without visiting it —
         * and on a forum with any private categories, a way to be told things
         * by proxy. Whether that content is public is the forum's decision, not
         * a guess this endpoint should make.
         */
        $actor->assertRegistered();

        if (! (bool) $this->settings->get('steward.answers')) {
            throw new ValidationException(['question' => $this->translator->trans('ernestdefoe-steward.api.answers_off')]);
        }

        $question = trim((string) Arr::get((array) $request->getParsedBody(), 'question', ''));

        if ($question === '') {
            throw new ValidationException(['question' => $this->translator->trans('ernestdefoe-steward.api.ask_first')]);
        }

        /*
         * 🚨 Only what this member could read on the forum is answered from or
         * cited. The passages are narrowed BEFORE they reach the model, so a
         * restricted thread can shape neither the answer nor the sources.
         * (Hosted retrieval holds only guest-visible posts; see IndexPost.)
         */
        $retrieval = $this->visibility->restrict($this->retrieval->find($question), $actor);

        $answer = $this->answerer->answer($question, $retrieval);

        /*
         * 🚨 Running out and breaking are told apart, and neither is dressed up
         * as an answer. A member sees a plain "I could not find that"; only an
         * admin needs to know which of the two it was.
         */
        if ($answer->unavailable) {
            return new JsonResponse([
                'answered' => false,
                'reason' => $answer->exhausted ? 'exhausted' : 'unavailable',
            ]);
        }

        if (! $answer->answered) {
            return new JsonResponse(['answered' => false, 'reason' => 'not_found']);
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

        return new JsonResponse([
            'answered' => true,
            'answer' => $answer->text,
            // Always cited. An answer built from the forum's own posts should
            // show which ones, so a member can check it rather than trust it.
            'sources' => array_map(fn (Passage $p) => [
                'title' => $p->title,
                'url' => $p->url,
            ], $sources),
        ]);
    }
}
