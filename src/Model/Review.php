<?php

namespace Ernestdefoe\Steward\Model;

use Flarum\Database\AbstractModel;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $post_id
 * @property int|null $user_id
 * @property string $action  review | guardian
 * @property string $source  pre-filter | model
 * @property string $reasons  JSON list of plain-words reasons
 * @property float $confidence
 * @property bool $unscreened
 * @property string|null $resolution  kept | removed | ignored
 * @property int|null $resolved_by
 * @property \Carbon\Carbon|null $resolved_at
 * @property \Carbon\Carbon|null $created_at
 * @property-read Post|null $post
 * @property-read User|null $user
 */
class Review extends AbstractModel
{
    protected $table = 'steward_reviews';
    public $timestamps = false;

    /*
     * 🚨 Unguarded on purpose, and safe here specifically.
     *
     * Flarum's AbstractModel guards mass assignment, so updateOrCreate() with
     * an attribute array throws MassAssignmentException — which the queue
     * swallows into a FAIL, so the post goes through and the review row simply
     * never appears. Silent, and exactly the shape of bug that makes a forum
     * believe it is moderated when it is not.
     *
     * Nothing user-supplied reaches these attributes: every field is written by
     * ScreenPost from a Decision the extension itself produced.
     */
    protected $guarded = [];

    protected $casts = [
        'confidence'  => 'float',
        'unscreened'  => 'bool',
        'created_at'  => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<string> */
    public function reasonList(): array
    {
        $decoded = json_decode((string) $this->reasons, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    public function open(): bool
    {
        return $this->resolution === null;
    }
}
