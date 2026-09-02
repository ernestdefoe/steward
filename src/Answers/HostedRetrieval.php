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
    public function find(string $question, int $limit = 5): Retrieval
    {
        return Retrieval::deferred();
    }

    public function available(): bool
    {
        return true;
    }
}
