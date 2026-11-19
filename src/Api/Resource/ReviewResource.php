<?php

namespace Ernestdefoe\Steward\Api\Resource;

use Carbon\Carbon;
use Ernestdefoe\Steward\Model\Review;
use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\OffsetPagination;

/**
 * The `steward-reviews` type: the moderation queue.
 *
 * GET   /api/steward-reviews?filter[status]=open|unscreened|resolved&page[offset]=N
 * PATCH /api/steward-reviews/{id}   {"resolution": "kept" | "removed" | "ignored"}
 *
 * 🚨 Resolving records a human's decision; it does not act on the post. Hiding
 * or deleting is done through Flarum's own moderation tools, by someone who
 * chose to — the difference matters most when the verdict was wrong.
 *
 * @extends AbstractDatabaseResource<Review>
 */
class ReviewResource extends AbstractDatabaseResource
{
    public const RESOLUTIONS = ['kept', 'removed', 'ignored'];

    public function __construct(
        private UrlGenerator $url,
        private SlugManager $slugs,
    ) {
    }

    public function type(): string
    {
        return 'steward-reviews';
    }

    public function model(): string
    {
        return Review::class;
    }

    /** @param Builder<Review> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->can('steward.review')
                ->eagerLoad(['post.discussion', 'user'])
                ->paginate(20, 50)
                ->query(function (Builder $query, $pagination, Context $context, array $filters) {
                    self::status($query, (string) ($filters['status'] ?? 'open'));
                    $query->orderByDesc('steward_reviews.created_at')->orderByDesc('steward_reviews.id');

                    if ($pagination instanceof OffsetPagination) {
                        $pagination->apply($query);
                    }

                    return $context->withQuery($query);
                })
                // The tab counts, so a moderator sees the true size of each pile.
                ->meta([
                    // Counted over the same reviews this person can see.
                    Schema\Arr::make('counts')->get(fn (Context $context) => [
                        'open' => self::status(Review::query()->whereVisibleTo($context->getActor()), 'open')->count(),
                        'unscreened' => self::status(Review::query()->whereVisibleTo($context->getActor()), 'unscreened')->count(),
                    ]),
                ]),
            Endpoint\Update::make()
                ->authenticated()
                ->can('steward.review'),
        ];
    }

    /**
     * 🚨 "Unscreened" is its own pile, not folded into "open".
     *
     * These are posts let through WITHOUT looking, because the allowance ran
     * out or the service was down. They are not suspicious — mixing them into
     * the suspicious pile would either bury real flags or train a moderator to
     * dismiss the whole queue.
     *
     * @param Builder<Review> $query
     * @return Builder<Review>
     */
    private static function status(Builder $query, string $status): Builder
    {
        return match ($status) {
            'unscreened' => $query->where('unscreened', true)->whereNull('resolution'),
            'resolved' => $query->whereNotNull('resolution'),
            default => $query->whereNull('resolution')->where('unscreened', false),
        };
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('postId')->property('post_id'),
            Schema\Str::make('action'),
            Schema\Str::make('source'),
            Schema\Arr::make('reasons')->get(fn (Review $r) => $r->reasonList()),
            Schema\Number::make('confidence'),
            Schema\Boolean::make('unscreened'),
            Schema\DateTime::make('createdAt')->property('created_at'),
            Schema\Str::make('author')->nullable()->get(fn (Review $r) => $r->user?->display_name),
            Schema\Str::make('excerpt')->get(fn (Review $r) => $r->excerpt()),
            Schema\Str::make('url')->nullable()->get(fn (Review $r) => $this->postUrl($r)),
            Schema\Str::make('resolution')
                ->writable()
                ->in(self::RESOLUTIONS)
                ->set(function (Review $r, string $value, Context $context) {
                    $r->resolution = $value;
                    $r->resolved_by = $context->getActor()->id;
                    $r->resolved_at = Carbon::now();
                }),
        ];
    }

    /** The post's own link, built by the forum's router and slug driver. */
    private function postUrl(Review $review): ?string
    {
        $discussion = $review->post?->discussion;

        return $discussion ? $this->url->to('forum')->route('discussion', [
            'id' => $this->slugs->forResource(Discussion::class)->toSlug($discussion),
            'near' => $review->post->number,
        ]) : null;
    }
}
