import app from 'flarum/admin/app';

app.initializers.add('ernestdefoe/steward', () => {
  app.extensionData
    .for('ernestdefoe-steward')
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
    .registerSetting({
      setting: 'steward.answer_threshold',
      type: 'text',
      label: app.translator.trans('ernestdefoe-steward.admin.settings.threshold_label'),
      help: app.translator.trans('ernestdefoe-steward.admin.settings.threshold_help'),
    });
});
