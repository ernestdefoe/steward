import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

declare const m: any;

const t = (k: string, p?: any) => app.translator.trans('ernestdefoe-steward.forum.queue.' + k, p);

interface Review {
  id: number; postId: number; action: string; source: string;
  reasons: string[]; confidence: number; unscreened: boolean;
  resolution: string | null; createdAt: string | null;
  author: string | null; excerpt: string; url: string | null;
}

export default class ReviewQueuePage extends Page {
  loading = true;
  reviews: Review[] = [];
  counts = { open: 0, unscreened: 0 };
  filter: 'open' | 'unscreened' | 'resolved' = 'open';
  busy: number | null = null;
  error: string | null = null;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    this.loading = true;
    m.redraw();

    app
      .request<{ reviews: Review[]; counts: any }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/steward/reviews',
        params: { filter: this.filter },
      })
      .then(
        (res) => {
          this.reviews = res.reviews || [];
          this.counts = res.counts || this.counts;
          this.loading = false;
          this.error = null;
          m.redraw();
        },
        () => {
          this.error = t('load_failed') as unknown as string;
          this.loading = false;
          m.redraw();
        }
      );
  }

  resolve(r: Review, resolution: string) {
    if (this.busy) return;
    this.busy = r.id;
    m.redraw();

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + `/steward/reviews/${r.id}/resolve`,
        body: { resolution },
      })
      .then(
        () => { this.busy = null; this.load(); },
        () => { this.busy = null; m.redraw(); }
      );
  }

  view() {
    return (
      <div className="StewardQueue">
        <div className="container">
          <header className="StewardQueue-head">
            <h1>{t('title')}</h1>
            <p className="StewardQueue-sub">{t('subtitle')}</p>
          </header>

          <nav className="StewardQueue-tabs">
            {([
              ['open', t('tab_open', { n: this.counts.open })],
              ['unscreened', t('tab_unscreened', { n: this.counts.unscreened })],
              ['resolved', t('tab_resolved')],
            ] as const).map(([key, label]) => (
              <button
                type="button"
                className={'StewardQueue-tab' + (this.filter === key ? ' is-on' : '')}
                onclick={() => { this.filter = key as any; this.load(); }}
              >
                {label}
              </button>
            ))}
          </nav>

          {/*
            * 🚨 The unscreened tab explains itself.
            *
            * These posts are not suspicious — they went through without being
            * looked at, because the allowance ran out or the service was down.
            * Without saying so, a moderator reads them as accusations and
            * either panics or learns to ignore the whole queue.
            */}
          {this.filter === 'unscreened' && (
            <div className="StewardQueue-note">{t('unscreened_explainer')}</div>
          )}

          {this.loading && <LoadingIndicator />}
          {this.error && <div className="StewardQueue-error">{this.error}</div>}

          {!this.loading && !this.error && this.reviews.length === 0 && (
            <div className="StewardQueue-empty">{t('empty_' + this.filter)}</div>
          )}

          {!this.loading &&
            this.reviews.map((r) => (
              <article className={'StewardCard' + (r.unscreened ? ' StewardCard--unscreened' : '')}>
                <div className="StewardCard-head">
                  <span className="StewardCard-who">{r.author || t('someone')}</span>
                  {r.action === 'guardian' && (
                    <span className="StewardCard-badge StewardCard-badge--guardian">
                      {t('guardian')}
                    </span>
                  )}
                  <span className="StewardCard-source">{t('by_' + r.source)}</span>
                </div>

                {/* Reasons first. A moderator reads why before they read what. */}
                <ul className="StewardCard-reasons">
                  {r.reasons.map((why) => (
                    <li>{why}</li>
                  ))}
                </ul>

                <blockquote className="StewardCard-excerpt">{r.excerpt}</blockquote>

                <div className="StewardCard-actions">
                  {r.url && (
                    <a className="Button Button--link" href={r.url}>
                      {t('open_post')}
                    </a>
                  )}
                  {!r.resolution &&
                    ['kept', 'removed', 'ignored'].map((res) =>
                      Button.component(
                        {
                          className: 'Button Button--link',
                          loading: this.busy === r.id,
                          onclick: () => this.resolve(r, res),
                        },
                        t('resolve_' + res)
                      )
                    )}
                  {r.resolution && (
                    <span className="StewardCard-resolved">{t('was_' + r.resolution)}</span>
                  )}
                </div>
              </article>
            ))}
        </div>
      </div>
    );
  }
}
