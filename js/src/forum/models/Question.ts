import Model from 'flarum/common/Model';

export interface Source {
  title: string;
  url: string;
}

/** A member's question and its answer (the `steward-questions` resource). */
export default class Question extends Model {
  question = Model.attribute<string>('question');
  status = Model.attribute<'pending' | 'done'>('status');
  answered = Model.attribute<boolean | null>('answered');
  answer = Model.attribute<string | null>('answer');
  reason = Model.attribute<string | null>('reason');
  sources = Model.attribute<Source[]>('sources');
}
