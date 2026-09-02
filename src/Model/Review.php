<?php

namespace Ernestdefoe\Steward\Model;

use Flarum\Database\AbstractModel;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

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
