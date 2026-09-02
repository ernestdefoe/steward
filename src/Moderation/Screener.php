<?php

namespace Ernestdefoe\Steward\Moderation;

use Ernestdefoe\Steward\Relay\RelayClient;
use Ernestdefoe\Steward\Relay\RelayException;
use Psr\Log\LoggerInterface;

/**
 * The paid half of moderation: asks the model about posts the cheap pass could
 * not settle.
 *
 * Everything here assumes it is expensive and fallible, because it is both.
 */
class Screener
{
    public function __construct(
        private PreFilter $preFilter,
        private RelayClient $relay,
        private LoggerInterface $log,
    ) {
    }

    /**
     * @param array{postCount:int, accountAgeDays:int, isModerator:bool} $author
     */
    public function screen(string $body, array $author): Decision
    {
        $pre = $this->preFilter->screen($body, $author);

        // Cleared or confidently flagged by the cheap pass — no model, no cost.
        if ($pre->outcome === Verdict::CLEAR) {
            return Decision::allow('cleared without a model call');
        }

        if ($pre->outcome === Verdict::FLAG) {
            return Decision::review($pre->reasons, 'pre-filter', $pre->confidence);
        }

        try {
            $res = $this->relay->post('v1/screen', [
                'text'    => mb_substr($body, 0, 6000),
                'signals' => $pre->reasons,
            ]);
        } catch (RelayException $e) {
            /*
             * 🚨 A moderation failure ALLOWS the post, and says so.
             *
             * This is the one place where failing open is right. Holding
             * everybody's posts because a billing period ended, or because the
             * relay had a bad minute, turns an outage into a forum that looks
             * broken to every member at once. The post goes through and the
             * event is recorded so an admin can look back over the window.
             *
             * The opposite choice — fail closed — is defensible for CSAM, which
             * is exactly why that is Guardian's job and not this one.
             */
            $this->log->warning('[steward] screening unavailable, allowing post', [
                'reason'    => $e->getMessage(),
                'exhausted' => $e->exhausted,
            ]);

            return Decision::allow(
                $e->exhausted
                    ? 'allowance used up — not screened'
                    : 'screening unavailable — not screened',
                unscreened: true
            );
        }

        return $this->interpret($res['data'], $pre->reasons);
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $preReasons
     */
    private function interpret(array $data, array $preReasons): Decision
    {
        $verdict = strtolower((string) ($data['verdict'] ?? 'allow'));
        $reason  = trim((string) ($data['reason'] ?? ''));
        $reasons = $reason !== '' ? [$reason] : $preReasons;

        /*
         * 🚨 CSAM is handed on, never judged here.
         *
         * Steward is a quality tool. A child-safety signal carries a legal
         * reporting duty and evidence handling that belong to Guardian, and
         * folding the two together would make every forum wanting spam triage
         * inherit that compliance surface. We stop, mark it, and escalate.
         */
        if ($verdict === 'csam' || ($data['csam'] ?? false)) {
            return Decision::escalateToGuardian($reasons);
        }

        return match ($verdict) {
            'remove', 'reject', 'spam' => Decision::review($reasons, 'model', 0.9),
            'review', 'unsure'         => Decision::review($reasons, 'model', 0.5),
            default                    => Decision::allow('the model saw nothing wrong'),
        };
    }
}
