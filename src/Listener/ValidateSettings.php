<?php

namespace Ernestdefoe\Steward\Listener;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\Event\Saving;

/**
 * Refuses a Steward setting that would quietly break it: a threshold outside
 * 0–1 (a typo read as 0 would answer from any weak match), a negative count,
 * or an address that is not a web URL.
 */
class ValidateSettings
{
    private const RULES = [
        'steward.answer_threshold' => 'fraction',
        'steward.trusted_posts' => 'count',
        'steward.new_account_days' => 'count',
        'steward.relay_url' => 'url',
        'steward.opensearch_url' => 'url',
    ];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function handle(Saving $event): void
    {
        $errors = [];

        foreach (array_intersect_key(self::RULES, $event->settings) as $key => $rule) {
            if (! self::valid($rule, trim((string) $event->settings[$key]))) {
                $errors[$key] = $this->translator->trans('ernestdefoe-steward.api.invalid_'.$rule);
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private static function valid(string $rule, string $value): bool
    {
        return match ($rule) {
            'fraction' => is_numeric($value) && (float) $value >= 0 && (float) $value <= 1,
            'count' => ctype_digit($value),
            default => $value === '' || (filter_var($value, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $value)),
        };
    }
}
