<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The moderation queue.
 *
 * 🚨 `reasons` is not optional and is stored as given. A moderator who cannot
 * see why something was queued will not trust the queue, and a filter whose
 * decisions nobody can read cannot be tuned. `unscreened` records the opposite
 * case — a post let through WITHOUT being looked at, because the allowance ran
 * out or the relay was down — so an admin can go back over that window rather
 * than assuming it was checked.
 */
return Migration::createTable('steward_reviews', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('post_id');
    $table->unsignedInteger('user_id')->nullable();
    $table->string('action', 20);            // review | guardian
    $table->string('source', 20);            // pre-filter | model
    $table->text('reasons');                 // JSON list of plain-words reasons
    $table->float('confidence')->default(0);
    $table->boolean('unscreened')->default(false);
    $table->string('resolution', 20)->nullable();   // kept | removed | ignored
    $table->unsignedInteger('resolved_by')->nullable();
    $table->dateTime('resolved_at')->nullable();
    $table->dateTime('created_at');

    $table->unique('post_id');
    $table->index(['resolution', 'created_at']);
});
