<?php

namespace Ernestdefoe\Steward\Answers;

/**
 * Where the passages behind an answer come from.
 *
 * 🚨 Two implementations, chosen by the tier, and the difference is not
 * technical — it is who holds the customer's content.
 *
 *  - Hosted: posts are shipped to the relay and indexed on our infrastructure.
 *    Nothing for the customer to run, so it sells to anyone. It also makes us a
 *    processor of their members' words, with everything that implies:
 *    retention, deletion on cancellation, and saying so plainly in the terms.
 *
 *  - Local: the customer's own OpenSearch does the retrieval and only the
 *    question and the passages it already holds ever leave their server. A
 *    harder sale — they need a cluster — but their content never becomes our
 *    problem.
 *
 * The seam exists so that choice stays a product decision rather than an
 * architectural one, and so a site can move between them without the answering
 * code knowing.
 */
interface RetrievalProvider
{
    /**
     * Passages that might answer this question, best first.
     *
     * Implementations must not apply the score threshold themselves — that
     * belongs to Retrieval, so both modes are judged by one standard rather
     * than each inventing its own idea of "good enough".
     */
    public function find(string $question, int $limit = 5): Retrieval;

    /** Whether this provider is configured enough to be asked anything. */
    public function available(): bool;
}
