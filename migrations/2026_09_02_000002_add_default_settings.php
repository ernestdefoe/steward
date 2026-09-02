<?php

use Flarum\Database\Migration;

return Migration::addSettings([
    // Issued by the client area and bound to this domain. No model provider
    // and no API key: which model runs is the relay's decision, because that
    // is what keeps a tier profitable.
    'steward.site_key'        => '',
    'steward.relay_url'       => 'https://relay.ernestdefoe.online',

    'steward.moderation'      => '1',
    'steward.answers'         => '1',

    // Measured, not guessed. On the live index, genuine questions floored at
    // 0.659 and a nonsense question still matched a real document at 0.576.
    // 0.62 sits between them. A different corpus will sit somewhere else.
    'steward.answer_threshold' => '0.62',

    // Above this post count a member is never screened.
    /*
     * Leave blank to have retrieval run on our servers — if the plan allows it.
     * Fill it in and retrieval runs on this forum's own cluster instead, and
     * its content never leaves.
     */
    'steward.opensearch_url'   => '',
    'steward.opensearch_index' => '',
    'steward.opensearch_model' => '',
    'steward.opensearch_user'  => '',
    'steward.opensearch_pass'  => '',

    'steward.trusted_posts'   => '25',
    'steward.new_account_days' => '7',
]);
