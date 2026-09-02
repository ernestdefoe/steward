<?php

use Flarum\Database\Migration;

/**
 * Who may see the moderation queue.
 *
 * 🚨 Granted to moderators (group 4), not just admins. Seeing WHY a post was
 * flagged — and seeing what went unchecked during an outage — is the job of
 * whoever actually moderates, and a queue only the owner can open is a queue
 * nobody reads.
 *
 * Deliberately its own permission rather than reusing `discussion.hide`:
 * reading the queue and being able to delete are different powers, and a forum
 * may reasonably want people who can do the first but not the second.
 */
return Migration::addPermissions([
    'steward.review' => 4,
]);
