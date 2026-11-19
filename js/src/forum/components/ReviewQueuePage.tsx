import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import type Review from '../models/Review';

// `m` is Flarum's global Mithril, declared in core's typings. Importing 'mithril'
// would bundle a second copy whose redraw never reaches Flarum's app.

const t = (k: string, p: Record<string, unknown> = {}) => app.translator.trans('ernestdefoe-steward.forum.queue.' + k, p);

type Filter = 'open' | 'unscreened' | 'resolved';

const RESOLUTIONS = ['kept', 'removed', 'ignored'];

export default class ReviewQueuePage extends Page {
  loading = true;
  reviews: Review[] = [];
  counts = { open: 0, unscreened: 0 };
  filter: Filter = 'open';
  more = false;
  busy: string | null = null;
  error: string | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.load();
  }

  /** The first page of the current tab, or (more) the next one after what is shown. */
  load(more = false) {
    this.loading = true;
    if (!more) this.reviews = [];

    app.store
      .find<Review[]>('steward-reviews', { filter: { status: this.filter }, page: { offset: this.reviews.length } })
      .then(
        (results) => {
          this.reviews = this.reviews.concat(results);
          this.counts = (results.payload.meta?.counts as typeof this.counts) || this.counts;
          this.more = !!results.payload.links?.next;
          this.error = null;
        },
        () => {
          this.error = t('load_failed') as unknown as string;
        }
      )
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  resolve(review: Review, resolution: string) {
    if (this.busy) return;
    this.busy = review.id()!;

    review
      .save({ resolution })
      .then(
        () => this.load(),
        () => {
          /* app.request already showed the alert */
        }
      )
      .finally(() => {
        this.busy = null;
        m.redraw();
      });
  }

  view() {
    return (
      <div className="StewardQueue">
        <div className="container">
          <header className="StewardQueue-head">
            <h1>{t('title')}</h1>
            <p className="StewardQueue-sub">{t('subtitle')}</p>
          </header>

          {this.tabs()}

          {/*
           * 🚨 The unscreened tab explains itself. These posts are not
           * suspicious — they went through without being looked at. Without
           * saying so, a moderator reads them as accusations.
           */}
          {this.filter === 'unscreened' && <div className="StewardQueue-note">{t('unscreened_explainer')}</div>}

          {this.error && <div className="StewardQueue-error">{this.error}</div>}
          {!this.loading && !this.error && this.reviews.length === 0 && <div className="StewardQueue-empty">{t('empty_' + this.filter)}</div>}

          {this.reviews.map((r) => this.card(r))}

          {this.loading && <LoadingIndicator />}
          {!this.loading && this.more && (
            <div className="StewardQueue-more">
              <Button className="Button" onclick={() => this.load(true)}>
                {t('load_more')}
              </Button>
            </div>
          )}
        </div>
      </div>
    );
  }

  tabs() {
    const tabs: [Filter, ReturnType<typeof t>][] = [
      ['open', t('tab_open', { n: this.counts.open })],
      ['unscreened', t('tab_unscreened', { n: this.counts.unscreened })],
      ['resolved', t('tab_resolved')],
    ];

    return (
      <nav className="StewardQueue-tabs">
        {tabs.map(([key, label]) => (
          <button
            type="button"
            className={'StewardQueue-tab' + (this.filter === key ? ' is-on' : '')}
            onclick={() => {
              this.filter = key;
              this.load();
            }}
          >
            {label}
          </button>
        ))}
      </nav>
    );
  }

  card(r: Review) {
    const resolution = r.resolution();

    return (
      <article key={r.id()} className={'StewardCard' + (r.unscreened() ? ' StewardCard--unscreened' : '')}>
        <div className="StewardCard-head">
          <span className="StewardCard-who">{r.author() || t('someone')}</span>
          {r.action() === 'guardian' && <span className="StewardCard-badge StewardCard-badge--guardian">{t('guardian')}</span>}
          <span className="StewardCard-source">{t('by_' + r.source())}</span>
        </div>

        {/* Reasons first. A moderator reads why before they read what. */}
        <ul className="StewardCard-reasons">
          {(r.reasons() || []).map((why) => (
            <li>{why}</li>
          ))}
        </ul>

        <blockquote className="StewardCard-excerpt">{r.excerpt()}</blockquote>

        <div className="StewardCard-actions">
          {r.url() && (
            <a className="Button Button--link" href={r.url()!}>
              {t('open_post')}
            </a>
          )}
          {!resolution &&
            RESOLUTIONS.map((res) => (
              <Button className="Button Button--link" loading={this.busy === r.id()} onclick={() => this.resolve(r, res)}>
                {t('resolve_' + res)}
              </Button>
            ))}
          {resolution && <span className="StewardCard-resolved">{t('was_' + resolution)}</span>}
        </div>
      </article>
    );
  }
}
