# Steward

Hosted AI for Flarum — **answers drawn from your own forum**, and **moderation
that reads a post before it flags it**. No AI account, no API key, no per-token
bill.

Distinct from **AI Helper**, which stays bring-your-own-key for people who want
to run their own model. Steward is sold as capacity through the client area and
adds moderation.

---

## What it does

### Answers, with sources

A member asks a question and gets an answer assembled from discussions already
on your site — with **every source cited**, so it can be checked rather than
trusted.

When nothing on the forum answers the question it says so. It does not reach out
to the wider internet, and it does not invent something plausible to fill the
gap. An answer you cannot trace back to a real post is worse than no answer.

Retrieval runs against your forum's own search, or — on plans that include it —
against a hosted semantic index, so answers are found by meaning rather than
keyword overlap.

### Moderation, that never deletes

Every new post is read before it lands. Most are cleared instantly and for
nothing; only genuinely ambiguous ones are looked at more closely.

- **Nothing is ever removed automatically.** The strongest outcome is "a person
  should look at this". Software that reads a post decides what a moderator
  *looks at*, never what disappears.
- **Every non-clear verdict carries reasons in plain words.** A queue you cannot
  read is a queue you cannot trust or tune.
- **Trusted members are never screened.** Spending money to second-guess someone
  who has posted politely for three years is worse than useless.
- **CSAM escalates** rather than being handled inline.
- **A moderation outage is not a posting outage.** If the service is
  unreachable, posts publish and are marked unscreened rather than blocked.

Flagged posts land in a review queue on your forum, for whoever holds the
`steward.review` permission.

### Usage where you will actually look

Your consumption, and what is left of it, appear in **Steward's own settings
page** — not on a billing portal you have to remember to visit. It shows the
daily trend, not just a total, because "60% used" does not tell you whether you
are about to run out.

---

## Install

```bash
composer require ernestdefoe/steward
```

Enable it, then paste your site key into its settings. The key is bound to one
domain and re-checked on every request, so it cannot be used anywhere else.

Requires **Flarum 2.0** and PHP 8.3+. A subscription is required:
<https://ernestdefoe.online/account>

---

## Your data

Only **public** content is ever sent. Posts in private or hidden discussions are
never indexed, and a post that stops being public is withdrawn from the index
rather than left behind.

On hosted retrieval your index is yours alone, and is **deleted when you
cancel** — on the same call that ends the service, not in a cleanup job somebody
remembers to run.

---

## Why the pre-filter exists

Moderation runs on every post, unlike an assistant which runs when someone asks.
A forum posting 500 times a day is 15,000 screenings a month; sending all of
them to a model costs more than the subscription — on precisely the busy forums
that need moderation most.

`Moderation\PreFilter` clears the obviously-fine majority for nothing and
escalates only what is genuinely ambiguous. Measured against 486 real posts:

| | share |
|---|---|
| Cleared free | 99.4% |
| Escalated to the model | 0.6% |

Among **untrusted** posters only — the population it actually inspects — the
escalation rate is 9.7%. Even on a forum where every poster is a newcomer that
is roughly $0.51 per 15,000 posts against $5.25 with no pre-filter.

---

## Support

- **Support site:** [ernestdefoe.online](https://ernestdefoe.online)
- **Issues:** [github.com/ernestdefoe/steward/issues](https://github.com/ernestdefoe/steward/issues)

## License

Proprietary — commercial license. © 2026 ernestdefoe.
