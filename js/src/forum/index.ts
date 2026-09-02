import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexPage from 'flarum/forum/components/IndexPage';
import LinkButton from 'flarum/common/components/LinkButton';
import ReviewQueuePage from './components/ReviewQueuePage';

export { default as ReviewQueuePage } from './components/ReviewQueuePage';

app.initializers.add('ernestdefoe/steward', () => {
  app.routes['steward.queue'] = { path: '/moderation/queue', component: ReviewQueuePage };

  extend(IndexPage.prototype, 'navItems', function (items: any) {
    /*
     * 🚨 Only for people who can act on it. A queue link shown to everyone is
     * either a permission error waiting to happen or an invitation to a page
     * that will refuse them.
     */
    if (!app.session.user || !app.forum.attribute('canReviewSteward')) return;

    items.add(
      'steward-queue',
      LinkButton.component(
        { href: app.route('steward.queue'), icon: 'fas fa-user-shield' },
        app.translator.trans('ernestdefoe-steward.forum.queue.nav')
      ),
      -10
    );
  });
});
