<?php

namespace Ernestdefoe\Steward\Moderation;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Decides which posts are worth spending a model call on.
 *
 * 🚨 This class is the difference between the product being viable and not.
 *
 * Moderation runs on EVERY post, unlike an assistant which runs when someone
 * asks. A forum posting 500 times a day is 15,000 screenings a month; sending
 * all of them to a model costs more than the subscription on a busy site — the
 * exact sites that need moderation. Most posts are plainly fine and can be
 * cleared for nothing, so the model only ever sees what is genuinely ambiguous.
 *
 * Three design rules, learned the hard way elsewhere:
 *
 *  - It never DELETES and never decides alone. Its worst outcome is "queue this
 *    for a human". A cheap heuristic that hides posts by itself will eventually
 *    hide a good one and the author will never know why.
 *
 *  - Every non-clear verdict carries reasons in plain words. A moderator who
 *    cannot see why something was flagged will not trust the queue, and you
 *    cannot tune a filter whose decisions you cannot read.
 *
 *  - Trusted members skip it entirely. Screening someone who has posted
 *    politely for three years is spending money to insult them.
 */
class PreFilter
{
    /**
     * @param int $trustedPosts   Post count above which a member is not screened.
     * @param int $newAccountDays Age below which an account gets more scrutiny.
     */
    public function __construct(
        private TranslatorInterface $translator,
        private int $trustedPosts,
        private int $newAccountDays,
    ) {
    }

    /**
     * A reason in the forum's language — it is stored with the review and read
     * by a moderator.
     *
     * @param array<string, mixed> $params
     */
    private function reason(string $key, array $params = []): string
    {
        return $this->translator->trans('ernestdefoe-steward.api.reasons.'.$key, $params);
    }

    /**
     * @param string $body        The post's source text.
     * @param array{postCount:int, accountAgeDays:int, isModerator:bool} $author
     */
    public function screen(string $body, array $author): Verdict
    {
        /*
         * 🚨 Trust short-circuits everything, and it is checked FIRST.
         *
         * Running the rules on an established member wastes a model call and,
         * worse, produces the occasional false flag on someone who has earned
         * not being treated as a suspect. Moderators are never screened at all.
         */
        if (($author['isModerator'] ?? false) || ($author['postCount'] ?? 0) >= $this->trustedPosts) {
            return Verdict::clear();
        }

        $text = trim($body);
        $new = ($author['accountAgeDays'] ?? 999) < $this->newAccountDays;
        $links = preg_match_all('~https?://~i', $text);
        $words = max(1, str_word_count(strip_tags($text)));

        [$reasons, $score] = $this->signals($text, $new, $links, $words);

        /*
         * 🚨 Scored, not decided.
         *
         * The cheap pass is only allowed to be certain when several independent
         * signals agree. One signal alone escalates to the model rather than
         * flagging, because any single heuristic here has a plausible innocent
         * explanation — a new member legitimately sharing a link, someone
         * quoting a repeated phrase.
         */
        if ($score >= 0.8 && count($reasons) >= 2) {
            return Verdict::flag($reasons, min(1.0, $score));
        }

        if ($reasons !== []) {
            return Verdict::escalate($reasons);
        }

        // Nothing tripped. A very short post from a brand-new account is still
        // worth a look — it is how most spam actually arrives — but nothing else is.
        if ($new && $words <= 3 && $links === 0) {
            return Verdict::escalate([$this->reason('almost_no_content')]);
        }

        return Verdict::clear();
    }

    /**
     * Each heuristic that tripped, with the weight it adds.
     *
     * @return array{0: list<string>, 1: float}
     */
    private function signals(string $text, bool $new, int $links, int $words): array
    {
        $hits = [];

        if ($links > 0 && $new) {
            $hits[] = [$this->reason('new_account_links', ['count' => $links]), $links >= 3 ? 0.55 : 0.3];
        }

        // A wall that is mostly URL is the classic drive-by.
        if ($links >= 2 && $words < 25) {
            $hits[] = [$this->reason('bare_links'), 0.4];
        }

        if (self::shouting($text)) {
            $hits[] = [$this->reason('capitals'), 0.25];
        }

        // Capped: a backreference over a very long post is slow, and a
        // repeated phrase shows long before 5,000 characters.
        if (preg_match('/(.{6,})\1{2,}/su', mb_substr($text, 0, 5000))) {
            $hits[] = [$this->reason('repetition'), 0.45];
        }

        if ($new && preg_match('/\b(whats ?app|telegram|t\.me\/|wechat|\+\d{8,})\b/i', mb_strtolower($text))) {
            $hits[] = [$this->reason('contact_details'), 0.5];
        }

        return [array_column($hits, 0), (float) array_sum(array_column($hits, 1))];
    }

    /** Mostly capitals, once there are enough letters to judge. */
    private static function shouting(string $text): bool
    {
        $letters = preg_replace('/[^a-z]/i', '', $text);

        return mb_strlen($letters) >= 20
            && mb_strlen(preg_replace('/[^A-Z]/', '', $text)) / mb_strlen($letters) > 0.7;
    }
}
