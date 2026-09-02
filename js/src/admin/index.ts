import app from 'flarum/admin/app';
import UsagePanel from './components/UsagePanel';

declare const m: any;

app.initializers.add('ernestdefoe/steward', () => {
  app.extensionData
    .for('ernestdefoe-steward')
    /*
     * 🚨 Usage first, above the settings.
     *
     * This is the thing an admin opens the page to find out, and burying it
     * under a form is how "why did the assistant stop working?" becomes a
     * support ticket instead of a glance.
     */
    .registerSetting(() => m(UsagePanel))
    .registerSetting({
      setting: 'steward.site_key',
      type: 'text',
      label: app.translator.trans('ernestdefoe-steward.admin.settings.site_key_label'),
      help: app.translator.trans('ernestdefoe-steward.admin.settings.site_key_help'),
    })
    .registerSetting({
      setting: 'steward.moderation',
      type: 'boolean',
      label: app.translator.trans('ernestdefoe-steward.admin.settings.moderation_label'),
      help: app.translator.trans('ernestdefoe-steward.admin.settings.moderation_help'),
    })
    .registerSetting({
      setting: 'steward.answers',
      type: 'boolean',
      label: app.translator.trans('ernestdefoe-steward.admin.settings.answers_label'),
      help: app.translator.trans('ernestdefoe-steward.admin.settings.answers_help'),
    })
    .registerSetting({
      setting: 'steward.trusted_posts',
      type: 'number',
      min: 0,
      label: app.translator.trans('ernestdefoe-steward.admin.settings.trusted_posts_label'),
      help: app.translator.trans('ernestdefoe-steward.admin.settings.trusted_posts_help'),
    })
    .registerPermission(
      {
        icon: 'fas fa-user-shield',
        label: app.translator.trans('ernestdefoe-steward.admin.permissions.review_label'),
        permission: 'steward.review',
      },
      'moderate'
    )
    .registerSetting({
      setting: 'steward.answer_threshold',
      type: 'text',
      label: app.translator.trans('ernestdefoe-steward.admin.settings.threshold_label'),
      help: app.translator.trans('ernestdefoe-steward.admin.settings.threshold_help'),
    });
});
