<?php

namespace Ernestdefoe\Steward\Api;

use Ernestdefoe\Steward\Model\Review;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/steward/reviews — the moderation queue.
 */
class ListReviewsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('steward.review');

        $params = $request->getQueryParams();
        $filter = (string) Arr::get($params, 'filter', 'open');

        $query = Review::query()
            ->with(['post', 'post.discussion', 'user'])
            ->orderByDesc('created_at')
            ->limit(100);

        /*
         * 🚨 "Unscreened" is its own filter, not folded into "open".
         *
         * These are posts we let through WITHOUT looking, because the allowance
         * ran out or the service was down. They are not suspicious — mixing
         * them into the suspicious pile would either bury real flags or train a
         * moderator to dismiss the whole queue. They answer a different
         * question: "what went unchecked, and when?"
         */
        match ($filter) {
            'unscreened' => $query->where('unscreened', true),
            'resolved'   => $query->whereNotNull('resolution'),
            default      => $query->whereNull('resolution')->where('unscreened', false),
        };

        return new JsonResponse([
            'reviews' => $query->get()->map(fn (Review $r) => [
                'id'         => (int) $r->id,
                'postId'     => (int) $r->post_id,
                'action'     => $r->action,
                'source'     => $r->source,
                'reasons'    => $r->reasonList(),
                'confidence' => (float) $r->confidence,
                'unscreened' => (bool) $r->unscreened,
                'resolution' => $r->resolution,
                'createdAt'  => $r->created_at?->toIso8601String(),
                'author'     => $r->user?->display_name,
                'excerpt'    => $this->excerpt($r),
                'url'        => $this->url($r),
            ])->values()->all(),
            'counts' => [
                'open'       => Review::query()->whereNull('resolution')->where('unscreened', false)->count(),
                'unscreened' => Review::query()->where('unscreened', true)->whereNull('resolution')->count(),
            ],
        ]);
    }

    private function excerpt(Review $r): string
    {
        $content = (string) ($r->post->content ?? '');
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($content)));

        return mb_substr($text, 0, 220);
    }

    private function url(Review $r): ?string
    {
        $d = $r->post?->discussion;

        return $d ? '/d/' . $d->id . '-' . $d->slug . '/' . $r->post->number : null;
    }
}
