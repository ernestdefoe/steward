import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

declare const m: any;

const t = (k: string, p?: any) => app.translator.trans('ernestdefoe-steward.forum.ask.' + k, p);

interface Source { title: string; url: string }

export default class AskPage extends Page {
  question = '';
  asking = false;
  answer: string | null = null;
  sources: Source[] = [];
  /** 'not_found' | 'exhausted' | 'unavailable' | null */
  miss: string | null = null;

  ask(e?: Event) {
    e?.preventDefault();

    const q = this.question.trim();
    if (!q || this.asking) return;

    this.asking = true;
    this.answer = null;
    this.sources = [];
    this.miss = null;
    m.redraw();

    app
      .request<any>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/steward/ask',
        body: { question: q },
        errorHandler: () => { this.asking = false; this.miss = 'unavailable'; m.redraw(); return false as const; },
      })
      .then(
        (res) => {
          this.asking = false;
          if (res.answered) {
            this.answer = res.answer;
            this.sources = res.sources || [];
          } else {
            this.miss = res.reason || 'not_found';
          }
          m.redraw();
        },
        () => {}
      );
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
              oninput={(e: any) => { this.question = e.target.value; e.redraw = false; }}
              disabled={this.asking}
            />
            {Button.component(
              { className: 'Button Button--primary', type: 'submit', loading: this.asking, disabled: this.asking },
              t('ask')
            )}
          </form>

          {this.asking && <div className="StewardAsk-thinking"><LoadingIndicator display="inline" size="small" /> {t('thinking')}</div>}

          {this.answer && (
            <div className="StewardAsk-answer">
              <div className="StewardAsk-text">{this.answer}</div>

              {/*
                * 🚨 Sources are always shown, never optional. The answer is
                * assembled from this forum's own posts, and a member should be
                * able to go and check it rather than take it on trust — an
                * uncited answer is indistinguishable from one that was made up.
                */}
              {this.sources.length > 0 && (
                <div className="StewardAsk-sources">
                  <div className="StewardAsk-sourcesLabel">{t('sources')}</div>
                  <ul>
                    {this.sources.map((s) => (
                      <li><a href={s.url}>{s.title || s.url}</a></li>
                    ))}
                  </ul>
                </div>
              )}

              <p className="StewardAsk-caveat">{t('caveat')}</p>
            </div>
          )}

          {/*
            * A miss is said plainly and differently from a fault. "I could not
            * find that" is a real answer; pretending otherwise is how these
            * things lose trust.
            */}
          {this.miss && (
            <div className="StewardAsk-miss">
              {t('miss_' + this.miss)}
            </div>
          )}
        </div>
      </div>
    );
  }
}
