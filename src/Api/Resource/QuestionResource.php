<?php

namespace Ernestdefoe\Steward\Api\Resource;

use Ernestdefoe\Steward\Answers\AskResults;
use Ernestdefoe\Steward\Answers\Question;
use Ernestdefoe\Steward\Job\AnswerQuestion;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractResource;
use Flarum\Api\Resource\Contracts\Creatable;
use Flarum\Api\Resource\Contracts\Findable;
use Flarum\Api\Schema;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Str;
use Tobyz\JsonApiServer\Context;
use Tobyz\JsonApiServer\Schema\Field\Field;

/**
 * The `steward-questions` type: a member asks, and reads the answer back.
 *
 * POST /api/steward-questions          {"question": "…"}
 * GET  /api/steward-questions/{id}     status "pending" until the queue answers
 *
 * 🚨 Registered members only, and not because of cost alone: every answer is
 * drawn from this forum's own content, so answering a signed-out stranger is
 * a way to read the forum without visiting it. The answer is worked out on the
 * queue (AnswerQuestion), never inside this request; on a forum whose queue
 * runs jobs straight away it is already in the create response. A question
 * someone else asked answers exactly like one that never existed.
 *
 * @extends AbstractResource<Question>
 */
class QuestionResource extends AbstractResource implements Findable, Creatable
{
    public function __construct(
        private AskResults $results,
        private Queue $queue,
        private SettingsRepositoryInterface $settings,
        private TranslatorInterface $translator,
    ) {
    }

    public function type(): string
    {
        return 'steward-questions';
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Create::make()->authenticated(),
            Endpoint\Show::make()->authenticated(),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('question')->writableOnCreate()->requiredOnCreate()->maxLength(2000),
            Schema\Str::make('status'),
            Schema\Boolean::make('answered')->nullable(),
            Schema\Str::make('answer')->nullable(),
            Schema\Str::make('reason')->nullable(),
            Schema\Arr::make('sources'),
        ];
    }

    public function getId(object $model, Context $context): string
    {
        return $model->id;
    }

    public function find(string $id, Context $context): ?object
    {
        $entry = $this->results->read($id, $context->getActor());

        return $entry === null ? null : Question::fromEntry($id, $entry);
    }

    public function newModel(Context $context): object
    {
        return new Question();
    }

    public function setValue(object $model, Field $field, mixed $value, Context $context): void
    {
        $model->question = trim((string) $value);
    }

    public function saveValue(object $model, Field $field, mixed $value, Context $context): void
    {
        // Nothing is stored field by field; create() hands the question to the queue.
    }

    /** @param Question $model */
    public function create(object $model, Context $context): object
    {
        if (! (bool) $this->settings->get('steward.answers')) {
            throw new ValidationException(['question' => $this->translator->trans('ernestdefoe-steward.api.answers_off')]);
        }

        if ($model->question === '') {
            throw new ValidationException(['question' => $this->translator->trans('ernestdefoe-steward.api.ask_first')]);
        }

        $actor = $context->getActor();
        $id = Str::random(40);

        $this->results->start($id, (int) $actor->id);
        $this->queue->push(new AnswerQuestion($id, (int) $actor->id, $model->question));

        return Question::fromEntry($id, $this->results->read($id, $actor) ?? []);
    }
}
