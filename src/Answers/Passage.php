<?php

namespace Ernestdefoe\Steward\Answers;

final class Passage
{
    public function __construct(
        public readonly string $title,
        public readonly string $text,
        public readonly string $url,
        public readonly float $score,
        /** The post this passage came from, when the index records it. */
        public readonly ?int $postId = null,
    ) {
    }

    /**
     * Safe to put in a link: a path on this forum or an ordinary web page.
     * A source comes from the relay or the forum's own index, so a
     * `javascript:` or `data:` URL in it must never reach an href.
     */
    public function linkable(): bool
    {
        return (bool) preg_match('~^(/(?!/)|https?://)~i', $this->url);
    }
}
