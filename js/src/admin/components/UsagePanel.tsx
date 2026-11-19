import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

// `m` is Flarum's global Mithril, declared in core's typings. Importing 'mithril'
// would bundle a second copy whose redraw never reaches Flarum's app.

const t = (k: string, p: Record<string, unknown> = {}) => app.translator.trans('ernestdefoe-steward.admin.usage.' + k, p);

interface Meter {
  used: number | null;
  limit: number | null;
  remaining: number | null;
}

interface Usage {
  connected: boolean;
  plan?: string;
  answers?: Meter;
  screenings?: Meter;
  resetsAt?: number;
  daily?: { day: string; actions: number }[];
  error?: string;
}

/**
 * 🚨 Shown here, on the customer's own forum, not only in the client area.
 *
 * Nobody visits a billing portal to check whether their AI still works. If the
 * only place the number lives is somewhere they have to remember to go, they
 * will not go — and the first they hear about running out is the assistant
 * quietly stopping.
 */
export default class UsagePanel extends Component {
  usage: Usage | null = null;
  loading = true;

  timer: ReturnType<typeof setTimeout> | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.load(10);
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    if (this.timer) clearTimeout(this.timer);
  }

  /** The relay is asked on the queue, so a first visit may need to check back. */
  load(triesLeft: number) {
    app.request<Usage & { pending?: boolean }>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/steward/usage' }).then(
      (res) => {
        if (res.pending && triesLeft > 1) {
          this.timer = setTimeout(() => this.load(triesLeft - 1), 3000);
          return;
        }
        this.usage = res.pending ? { connected: false, error: t('pending') as unknown as string } : res;
        this.loading = false;
        m.redraw();
      },
      () => {
        this.usage = { connected: false };
        this.loading = false;
        m.redraw();
      }
    );
  }

  view() {
    if (this.loading)
      return (
        <div className="StewardUsage">
          <LoadingIndicator />
        </div>
      );

    const u = this.usage!;

    if (!u.connected) {
      return (
        <div className="StewardUsage StewardUsage--off">
          <p>{u.error || t('not_connected')}</p>
        </div>
      );
    }

    return (
      <div className="StewardUsage">
        <div className="StewardUsage-head">
          <h3>{t('title')}</h3>
          {u.plan && <span className="StewardUsage-plan">{u.plan}</span>}
        </div>

        {this.bar(t('answers'), u.answers)}
        {this.bar(t('screenings'), u.screenings)}
        {this.chart(u.daily || [])}

        {u.resetsAt && (
          <p className="StewardUsage-reset">
            {t('resets', {
              /*
               * 🚨 Render in UTC. The period boundary is a UTC midnight, so
               * formatting it in the viewer's local zone shows the last day of
               * the month as the second-to-last one anywhere west of London —
               * a billing date that is visibly wrong to the customer.
               */
              date: new Date(u.resetsAt * 1000).toLocaleDateString(undefined, { timeZone: 'UTC' }),
            })}
          </p>
        )}
      </div>
    );
  }

  bar(label: ReturnType<typeof t>, m2?: Meter) {
    if (!m2) return null;

    // No limit is a real state, not a zero. Rendering an empty bar for an
    // unmetered plan would read as "nothing left".
    if (m2.limit === null) {
      // Still show the count. On an unmetered plan nothing else bounds usage,
      // so the running total is the only figure worth looking at — printing
      // just the word "unmetered" tells an admin strictly less than nothing.
      return (
        <div className="StewardUsage-meter">
          <div className="StewardUsage-meterHead">
            <span>{label}</span>
            <span>{t('unmeteredUsed', { n: (m2.used ?? 0).toLocaleString() })}</span>
          </div>
        </div>
      );
    }

    const used = m2.used ?? 0;
    const pct = m2.limit ? Math.min(100, Math.round((used / m2.limit) * 100)) : 0;
    const low = pct >= 90;

    return (
      <div className="StewardUsage-meter">
        <div className="StewardUsage-meterHead">
          <span>{label}</span>
          <span className={low ? 'is-low' : ''}>{t('left', { n: (m2.remaining ?? 0).toLocaleString(), total: m2.limit.toLocaleString() })}</span>
        </div>
        <div className="StewardUsage-track">
          <div className={'StewardUsage-fill' + (low ? ' is-low' : '')} style={{ width: pct + '%' }} />
        </div>
      </div>
    );
  }

  /** Thirty days of daily actions. The slope is the useful part. */
  chart(daily: { day: string; actions: number }[]) {
    if (daily.length < 2) return null;

    const max = Math.max(...daily.map((d) => d.actions), 1);
    const w = 100 / daily.length;

    return (
      <div className="StewardUsage-chart" role="img" aria-label={t('chart_label', { n: daily.length })}>
        {daily.map((d) => (
          <div
            className="StewardUsage-day"
            style={{ width: w + '%', height: Math.max(2, (d.actions / max) * 100) + '%' }}
            title={`${d.day}: ${d.actions}`}
          />
        ))}
      </div>
    );
  }
}
