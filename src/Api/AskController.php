<?php

namespace Ernestdefoe\Steward\Api;

use Ernestdefoe\Steward\Answers\Answerer;
use Ernestdefoe\Steward\Answers\Passage;
use Ernestdefoe\Steward\Answers\RetrievalProvider;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
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
            throw new ValidationException(['question' => 'Answers are switched off on this forum.']);
        }

        $question = trim((string) Arr::get((array) $request->getParsedBody(), 'question', ''));

        if ($question === '') {
            throw new ValidationException(['question' => 'Ask a question first.']);
        }

        $answer = $this->answerer->answer($question, $this->retrieval->find($question));

        /*
         * 🚨 Running out and breaking are told apart, and neither is dressed up
         * as an answer. A member sees a plain "I could not find that"; only an
         * admin needs to know which of the two it was.
         */
        if ($answer->unavailable) {
            return new JsonResponse([
                'answered' => false,
                'reason'   => $answer->exhausted ? 'exhausted' : 'unavailable',
            ]);
        }

        if (! $answer->answered) {
            return new JsonResponse(['answered' => false, 'reason' => 'not_found']);
        }

        return new JsonResponse([
            'answered' => true,
            'answer'   => $answer->text,
            // Always cited. An answer built from the forum's own posts should
            // show which ones, so a member can check it rather than trust it.
            'sources'  => array_map(fn (Passage $p) => [
                'title' => $p->title,
                'url'   => $p->url,
            ], $answer->sources),
        ]);
    }
}
