import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

export { default as extend } from './extend';

app.initializers.add('ernestdefoe/steward', () => {
  /*
   * 🚨 IndexSidebar, not IndexPage: Flarum 2 moved the nav items there, and an
   * extend() of a method that no longer exists adds one nobody calls — neither
   * link was ever shown.
   */
  extend(IndexSidebar.prototype, 'navItems', function (items: ItemList<Mithril.Children>) {
    if (!app.session.user) return;

    // Asking is for members; the queue is for whoever moderates.
    if (app.forum.attribute('stewardAnswers')) {
      items.add(
        'steward-ask',
        LinkButton.component(
          { href: app.route('steward.ask'), icon: 'fas fa-wand-magic-sparkles' },
          app.translator.trans('ernestdefoe-steward.forum.ask.nav')
        ),
        -9
      );
    }

    /*
     * 🚨 Only for people who can act on it. A queue link shown to everyone is
     * either a permission error waiting to happen or an invitation to a page
     * that will refuse them.
     */
    if (!app.forum.attribute('canReviewSteward')) return;

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
