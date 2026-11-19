import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import type Question from '../models/Question';
import type { Source } from '../models/Question';

const t = (k: string, p: Record<string, unknown> = {}) => app.translator.trans('ernestdefoe-steward.forum.ask.' + k, p);

/** Only links into the forum itself, or ordinary web pages, are ever rendered. */
const safe = (url: string) => /^(\/(?!\/)|https?:\/\/)/i.test(url);

/** Errors are shown on the page as "unavailable", not as an alert. */
const quiet = () => {
  /* settle() shows the miss */
};

/** How often, and for how long, to check back for a queued answer. */
const POLL_MS = 1500;
const GIVE_UP_MS = 90000;

export default class AskPage extends Page {
  question = '';
  asking = false;
  answer: string | null = null;
  sources: Source[] = [];
  /** 'not_found' | 'exhausted' | 'unavailable' | null */
  miss: string | null = null;
  timer: ReturnType<typeof setTimeout> | null = null;

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    if (this.timer) clearTimeout(this.timer);
  }

  ask(e?: Event) {
    e?.preventDefault();

    const q = this.question.trim();
    if (!q || this.asking) return;

    this.asking = true;
    this.answer = null;
    this.sources = [];
    this.miss = null;

    app.store
      .createRecord<Question>('steward-questions')
      .save({ question: q }, { errorHandler: quiet })
      .then(
        (asked) => this.settle(asked, Date.now() + GIVE_UP_MS),
        () => this.settle(null, 0)
      );
  }

  /**
   * 🚨 The answer is worked out on the queue, so a forum whose queue runs in the
   * background replies "pending" and this checks back until it is ready.
   */
  settle(asked: Question | null, deadline: number) {
    if (asked && asked.status() === 'pending' && Date.now() < deadline) {
      this.timer = setTimeout(() => {
        app.store.find<Question>('steward-questions', asked.id()!, {}, { errorHandler: quiet }).then(
          (next) => this.settle(next, deadline),
          () => this.settle(null, 0)
        );
      }, POLL_MS);
      return;
    }

    this.asking = false;
    if (asked && asked.status() === 'done' && asked.answered()) {
      this.answer = asked.answer() || '';
      this.sources = (asked.sources() || []).filter((s) => safe(s.url));
    } else {
      this.miss = asked && asked.status() === 'done' ? asked.reason() || 'not_found' : 'unavailable';
    }
    m.redraw();
  }

  view() {
    return (
      <div className="StewardAsk">
        <div className="container">
          <h1 className="StewardAsk-title">{t('title')}</h1>
          <p className="StewardAsk-sub">{t('subtitle')}</p>

          <form className="StewardAsk-form" onsubmit={(e: Event) => this.ask(e)}>
            <input
              className="FormControl StewardAsk-input"
              placeholder={t('placeholder')}
              value={this.question}
              oninput={(e: InputEvent & { redraw?: boolean }) => {
                this.question = (e.target as HTMLInputElement).value;
                e.redraw = false;
              }}
              disabled={this.asking}
            />
            <Button className="Button Button--primary" type="submit" loading={this.asking} disabled={this.asking}>
              {t('ask')}
            </Button>
          </form>

          {this.asking && (
            <div className="StewardAsk-thinking">
              <LoadingIndicator display="inline" size="small" /> {t('thinking')}
            </div>
          )}

          {this.answer && this.answerView()}

          {/*
           * A miss is said plainly and differently from a fault. "I could not
           * find that" is a real answer.
           */}
          {this.miss && <div className="StewardAsk-miss">{t('miss_' + this.miss)}</div>}
        </div>
      </div>
    );
  }

  answerView() {
    return (
      <div className="StewardAsk-answer">
        <div className="StewardAsk-text">{this.answer}</div>

        {/*
         * 🚨 Sources are always shown. The answer is assembled from this
         * forum's own posts, and an uncited answer is indistinguishable from
         * one that was made up.
         */}
        {this.sources.length > 0 && (
          <div className="StewardAsk-sources">
            <div className="StewardAsk-sourcesLabel">{t('sources')}</div>
            <ul>
              {this.sources.map((s) => (
                <li>
                  <a href={s.url}>{s.title || s.url}</a>
                </li>
              ))}
            </ul>
          </div>
        )}

        <p className="StewardAsk-caveat">{t('caveat')}</p>
      </div>
    );
  }
}
