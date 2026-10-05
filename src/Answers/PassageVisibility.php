<?php

namespace Ernestdefoe\Steward\Answers;

use Flarum\Post\Post;
use Flarum\User\User;

/**
 * Drops every retrieved passage the asker could not read on the forum.
 *
 * 🚨 A local index is the customer's own, and nothing stops it holding staff
 * tags, private discussions or posts awaiting approval. Retrieval has no idea
 * who is asking, so without this an ordinary member could be answered from a
 * thread they cannot open, and be handed its title and link as a citation.
 *
 * Every passage that is a forum post (it carries a post id, or links to a
 * discussion) is kept only if the asker can see that post. A passage that is
 * not a forum post at all, such as documentation the operator chose to put in
 * their own index, is not this check's to judge and is kept as before.
 *
 * One query for the whole set, whatever the number of passages.
 */
class PassageVisibility
{
    public function restrict(Retrieval $retrieval, User $actor): Retrieval
    {
        if ($retrieval->deferred || $retrieval->passages === []) {
            return $retrieval;
        }

        $located = array_map(fn (Passage $p) => self::locate($p), $retrieval->passages);
        $ids = array_values(array_filter(array_column(array_filter($located), 'post')));
        $pairs = array_values(array_filter($located, fn ($l) => $l && ! $l['post'] && $l['discussion']));

        if (! $ids && ! $pairs) {
            return $retrieval; // nothing in it is a forum post
        }

        $visible = Post::query()
            ->whereVisibleTo($actor)
            ->where('type', 'comment')
            ->where(function ($q) use ($ids, $pairs) {
                if ($ids) {
                    $q->orWhereIn('posts.id', $ids);
                }
                foreach ($pairs as $pair) {
                    $q->orWhere(function ($q) use ($pair) {
                        $q->where('posts.discussion_id', $pair['discussion'])
                            ->where('posts.number', $pair['number'] ?? 1);
                    });
                }
            })
            ->get(['posts.id', 'posts.discussion_id', 'posts.number']);

        $seenIds = [];
        $seenPairs = [];
        foreach ($visible as $post) {
            $seenIds[(int) $post->id] = true;
            $seenPairs[$post->discussion_id . ':' . $post->number] = true;
        }

        return $retrieval->only(function (Passage $p) use ($seenIds, $seenPairs) {
            $where = self::locate($p);
            if (! $where) {
                return true; // not a forum post
            }

            return $where['post']
                ? isset($seenIds[$where['post']])
                : isset($seenPairs[$where['discussion'] . ':' . ($where['number'] ?? 1)]);
        });
    }

    /**
     * Where a passage lives on the forum: its post id when the index records
     * one, otherwise the discussion (and post number) read from its link.
     * A link with no post number is the discussion's first post.
     *
     * @return array{post: ?int, discussion: ?int, number: ?int}|null
     */
    public static function locate(Passage $passage): ?array
    {
        if ($passage->postId) {
            return ['post' => $passage->postId, 'discussion' => null, 'number' => null];
        }

        $path = (string) (parse_url($passage->url, PHP_URL_PATH) ?? '');

        if (preg_match('~/d/(\d+)(?:-[^/]*)?(?:/(\d+))?/?$~', $path, $m)) {
            return ['post' => null, 'discussion' => (int) $m[1], 'number' => isset($m[2]) ? (int) $m[2] : null];
        }

        return null;
    }
}
