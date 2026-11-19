import Extend from 'flarum/common/extenders';
import Question from './models/Question';
import Review from './models/Review';

export default [
  new Extend.Store().add('steward-reviews', Review).add('steward-questions', Question),

  // Lazy: only moderators open the queue and only askers open /ask, so neither
  // page is in the forum.js every visitor downloads.
  new Extend.Routes()
    .add('steward.queue', '/moderation/queue', () => import('./components/ReviewQueuePage'))
    .add('steward.ask', '/ask', () => import('./components/AskPage')),
];
