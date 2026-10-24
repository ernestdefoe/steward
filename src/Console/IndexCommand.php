<?php

namespace Ernestdefoe\Steward\Console;

use Ernestdefoe\Steward\Job\IndexPost;
use Ernestdefoe\Steward\Relay\RelayClient;
use Flarum\Console\AbstractCommand;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use Illuminate\Contracts\Queue\Queue;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputOption;

/**
 * php flarum steward:index.
 *
 * Sends every public post already on the forum to the hosted answer index.
 *
 * 🚨 New, edited and restored posts are indexed as they happen, but posts made
 * before Steward was installed fire no event, so without this they are never
 * answered from. Chunked by id, and the last id done is saved after every
 * chunk: an interrupted run picks up where it stopped (--restart starts over).
 * Only what a guest can read is queued; IndexPost re-checks each post anyway.
 */
class IndexCommand extends AbstractCommand
{
    public const CURSOR = 'steward.index_cursor';

    public function __construct(
        private Queue $queue,
        private SettingsRepositoryInterface $settings,
        private RelayClient $relay,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('steward:index')
            ->setDescription('Send every public post to the Steward answer index (resumes where it stopped).')
            ->addOption('restart', null, InputOption::VALUE_NONE, 'Start again from the first post.')
            ->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Posts read per batch.', '500');
    }

    protected function fire(): int
    {
        if ((string) $this->settings->get('steward.opensearch_url') !== '') {
            $this->info('This forum answers from its own OpenSearch index, so there is nothing to send.');

            return 0;
        }

        if (! $this->relay->configured()) {
            $this->error('Steward is not connected — paste the site key into its settings first.');

            return 1;
        }

        if ($this->input->getOption('restart')) {
            $this->settings->delete(self::CURSOR);
        }

        $from = (int) $this->settings->get(self::CURSOR);
        $chunk = max(1, (int) $this->input->getOption('chunk'));
        $query = CommentPost::query()->whereVisibleTo(new Guest())
            ->where('posts.type', 'comment')
            ->where('posts.id', '>', $from);

        if ($from > 0) {
            $this->info("Resuming after post {$from}.");
        }

        $bar = new ProgressBar($this->output, $query->clone()->count());
        $queued = 0;

        $query->select('posts.id')->chunkById($chunk, function ($posts) use ($bar, &$queued) {
            foreach ($posts as $post) {
                $this->queue->push(new IndexPost((int) $post->id));
            }

            $queued += $posts->count();
            $bar->advance($posts->count());
            $this->settings->set(self::CURSOR, (string) $posts->last()->id);
        }, 'posts.id', 'id');

        $bar->finish();
        $this->output->writeln('');
        $this->info("Queued {$queued} posts for indexing.");

        return 0;
    }
}
