<?php

namespace Ernestdefoe\Steward\Model;

use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
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
    use ScopeVisibilityTrait;

    protected $table = 'steward_reviews';
    public $timestamps = false;

    /*
     * 🚨 Listed, so updateOrCreate() in ScreenPost can write them. Flarum's
     * AbstractModel guards mass assignment, and a MassAssignmentException in
     * the job fails silently: the post goes through and the review row never
     * appears. The resolution fields are not here; only ReviewResource sets
     * them, one by one, from a moderator's request.
     */
    protected $fillable = ['post_id', 'user_id', 'action', 'source', 'reasons', 'confidence', 'unscreened', 'created_at'];

    protected $casts = [
        'confidence' => 'float',
        'unscreened' => 'bool',
        'created_at' => 'datetime',
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

    /** The post's first words as plain text, for the queue card. */
    public function excerpt(): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($this->post->content ?? ''))));

        return mb_substr($text, 0, 220);
    }
}
