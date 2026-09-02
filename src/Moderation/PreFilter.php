<?php

namespace Ernestdefoe\Steward\Moderation;

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
        private int $trustedPosts = 25,
        private int $newAccountDays = 7,
    ) {
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

        $text  = trim($body);
        $lower = mb_strtolower($text);
        $new   = ($author['accountAgeDays'] ?? 999) < $this->newAccountDays;

        $reasons = [];
        $score   = 0.0;

        // --- link density -------------------------------------------------
        $links = preg_match_all('~https?://~i', $text);
        $words = max(1, str_word_count(strip_tags($text)));

        if ($links > 0 && $new) {
            $reasons[] = "new account posting {$links} link" . ($links === 1 ? '' : 's');
            $score += $links >= 3 ? 0.55 : 0.3;
        }

        // A wall that is mostly URL is the classic drive-by.
        if ($links >= 2 && $words < 25) {
            $reasons[] = 'links with almost no text around them';
            $score += 0.4;
        }

        // --- shouting -------------------------------------------------------
        $letters = preg_replace('/[^a-z]/i', '', $text);
        if (mb_strlen($letters) >= 20) {
            $upper = preg_replace('/[^A-Z]/', '', $text);
            $ratio = mb_strlen($upper) / mb_strlen($letters);
            if ($ratio > 0.7) {
                $reasons[] = 'almost entirely capitals';
                $score += 0.25;
            }
        }

        // --- repetition -----------------------------------------------------
        if (preg_match('/(.{6,})\1{2,}/su', $text)) {
            $reasons[] = 'the same phrase repeated over and over';
            $score += 0.45;
        }

        // --- contact-details bait ------------------------------------------
        if ($new && preg_match('/\b(whats ?app|telegram|t\.me\/|wechat|\+\d{8,})\b/i', $lower)) {
            $reasons[] = 'new account sharing off-site contact details';
            $score += 0.5;
        }

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

        /*
         * Nothing tripped. A very short post from a brand-new account is still
         * worth a look — it is how most spam actually arrives — but nothing
         * else is.
         */
        if ($new && $words <= 3 && $links === 0) {
            return Verdict::escalate(['first posts, almost no content']);
        }

        return Verdict::clear();
    }
}
