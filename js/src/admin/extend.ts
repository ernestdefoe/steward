import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';
import UsagePanel from './components/UsagePanel';

// `m` is Flarum's global Mithril, declared in core's typings. Importing 'mithril'
// would bundle a second copy whose redraw never reaches Flarum's app.

const t = (k: string) => app.translator.trans('ernestdefoe-steward.admin.' + k);

export default [
  new Extend.Admin()
    /*
     * 🚨 Usage first, above the settings.
     *
     * This is the thing an admin opens the page to find out, and burying it
     * under a form is how "why did the assistant stop working?" becomes a
     * support ticket instead of a glance.
     */
    /*
     * 🚨 customSetting(), not setting(). setting() expects a field DESCRIPTOR
     * and renders a bare empty input when handed a component — it does not
     * error, it just silently draws an anonymous grey box above the form.
     * A high priority floats it above the settings.
     */
    .customSetting(() => m(UsagePanel), 100)

    .setting(() => ({
      setting: 'steward.site_key',
      type: 'text',
      label: t('settings.site_key_label'),
      help: t('settings.site_key_help'),
    }))
    .setting(() => ({
      setting: 'steward.moderation',
      type: 'boolean',
      label: t('settings.moderation_label'),
      help: t('settings.moderation_help'),
    }))
    .setting(() => ({
      setting: 'steward.answers',
      type: 'boolean',
      label: t('settings.answers_label'),
      help: t('settings.answers_help'),
    }))
    .setting(() => ({
      setting: 'steward.trusted_posts',
      type: 'number',
      min: 0,
      label: t('settings.trusted_posts_label'),
      help: t('settings.trusted_posts_help'),
    }))
    .setting(() => ({
      setting: 'steward.new_account_days',
      type: 'number',
      min: 0,
      label: t('settings.new_account_days_label'),
      help: t('settings.new_account_days_help'),
    }))
    .setting(() => ({
      setting: 'steward.answer_threshold',
      type: 'number',
      min: 0,
      max: 1,
      step: 0.01,
      label: t('settings.threshold_label'),
      help: t('settings.threshold_help'),
    }))
    .setting(() => ({
      setting: 'steward.relay_url',
      type: 'url',
      label: t('settings.relay_url_label'),
      help: t('settings.relay_url_help'),
    }))

    // Local retrieval: the forum's own cluster, instead of the hosted index.
    .setting(() => ({
      setting: 'steward.opensearch_url',
      type: 'url',
      label: t('settings.opensearch_url_label'),
      help: t('settings.opensearch_help'),
    }))
    .setting(() => ({ setting: 'steward.opensearch_index', type: 'text', label: t('settings.opensearch_index_label') }))
    .setting(() => ({ setting: 'steward.opensearch_model', type: 'text', label: t('settings.opensearch_model_label') }))
    .setting(() => ({ setting: 'steward.opensearch_user', type: 'text', label: t('settings.opensearch_user_label') }))
    .setting(() => ({ setting: 'steward.opensearch_pass', type: 'password', label: t('settings.opensearch_pass_label') }))
    .setting(() => ({
      setting: 'steward.opensearch_ca',
      type: 'text',
      label: t('settings.opensearch_ca_label'),
      help: t('settings.opensearch_ca_help'),
    }))

    .permission(
      () => ({
        icon: 'fas fa-user-shield',
        label: t('permissions.review_label'),
        permission: 'steward.review',
      }),
      'moderate'
    ),
];
