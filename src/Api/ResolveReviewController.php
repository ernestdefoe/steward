<?php

namespace Ernestdefoe\Steward\Api;

use Carbon\Carbon;
use Ernestdefoe\Steward\Model\Review;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/steward/reviews/{id}/resolve
 *
 * 🚨 Resolving records a human's decision; it does not act on the post.
 *
 * Hiding or deleting is done through Flarum's own moderation tools, by someone
 * who chose to. Steward marking a post "removed" and removing it would be the
 * automated deletion this design refuses — the difference matters most when the
 * verdict was wrong, which is the case nobody plans for.
 */
class ResolveReviewController implements RequestHandlerInterface
{
    private const ALLOWED = ['kept', 'removed', 'ignored'];

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('steward.review');

        $id = (int) ($request->getAttribute('routeParameters')['id'] ?? 0);
        $resolution = (string) Arr::get((array) $request->getParsedBody(), 'resolution');

        if (! in_array($resolution, self::ALLOWED, true)) {
            throw new ValidationException(['resolution' => 'Unknown resolution.']);
        }

        /** @var Review $review */
        $review = Review::query()->findOrFail($id);

        $review->resolution  = $resolution;
        $review->resolved_by = $actor->id;
        $review->resolved_at = Carbon::now();
        $review->save();

        return new JsonResponse(['ok' => true, 'id' => $review->id, 'resolution' => $resolution]);
    }
}
