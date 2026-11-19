<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * A review goes with its post, and a deleted account no longer names anyone.
 * Rows left behind by posts and users deleted before this are cleared first,
 * or the keys could not be added.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $db->table('steward_reviews')->whereNotExists(fn ($q) => $q->from('posts')->whereColumn('posts.id', 'steward_reviews.post_id'))->delete();

        foreach (['user_id', 'resolved_by'] as $column) {
            $db->table('steward_reviews')
                ->whereNotNull($column)
                ->whereNotExists(fn ($q) => $q->from('users')->whereColumn('users.id', 'steward_reviews.'.$column))
                ->update([$column => null]);
        }

        $schema->table('steward_reviews', function (Blueprint $table) {
            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('steward_reviews', function (Blueprint $table) {
            $table->dropForeign(['post_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['resolved_by']);
        });
    },
];
