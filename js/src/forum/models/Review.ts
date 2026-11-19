import Model from 'flarum/common/Model';

/** One entry in the moderation queue (the `steward-reviews` resource). */
export default class Review extends Model {
  postId = Model.attribute<number>('postId');
  action = Model.attribute<string>('action');
  source = Model.attribute<string>('source');
  reasons = Model.attribute<string[]>('reasons');
  confidence = Model.attribute<number>('confidence');
  unscreened = Model.attribute<boolean>('unscreened');
  resolution = Model.attribute<string | null>('resolution');
  createdAt = Model.attribute<Date | null, string | null>('createdAt', Model.transformDate);
  author = Model.attribute<string | null>('author');
  excerpt = Model.attribute<string>('excerpt');
  url = Model.attribute<string | null>('url');
}
