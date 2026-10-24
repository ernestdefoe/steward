<?php

namespace Ernestdefoe\Steward\Answers;

/**
 * Hosted mode: the index lives on our infrastructure, so the relay retrieves
 * for itself and this does nothing but say so.
 *
 * The customer runs no search infrastructure at all, which is what makes this
 * sellable to anyone — and it is also why it makes us a processor of their
 * members' words. That obligation is the price of the easy sale, and it should
 * be visible in the terms rather than buried in an architecture diagram.
 */
class HostedRetrieval implements RetrievalProvider
{
    /**
     * 🚨 The forum's own confidence threshold travels with the question, so a
     * hosted forum is judged by the same setting as one running its own
     * OpenSearch. The relay scores with the same neural query either way.
     */
    public function __construct(private float $threshold = 0.0)
    {
    }

    public function find(string $question, int $limit = 5): Retrieval
    {
        return Retrieval::deferred($this->threshold);
    }

    public function available(): bool
    {
        return true;
    }
}
