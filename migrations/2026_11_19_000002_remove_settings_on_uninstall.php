<?php

use Illuminate\Database\Schema\Builder;

/*
 * Nothing to add: Steward's defaults live in extend.php. On uninstall (purge)
 * every steward.* setting goes, the site key and OpenSearch password included,
 * so no credential outlives the extension.
 */
return [
    'up' => function (Builder $schema) {
        // Intentionally empty; this migration exists for its down step.
    },

    'down' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->where('key', 'like', 'steward.%')->delete();
    },
];
