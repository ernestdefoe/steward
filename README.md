# Steward

Hosted AI for Flarum — answers drawn from your own forum, and moderation that
reads a post before it flags it. No AI account, no API key, no per-token bill.

Distinct from **AI Helper**, which stays bring-your-own-key for people who want
to run their own model. Steward shares its retrieval engine and adds moderation,
sold on credits through the client area.

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

## Rules it will not break

- It never deletes and never decides alone. Its worst outcome is "queue this for
  a human".
- Every non-clear verdict carries reasons in plain words. A queue you cannot
  read is a queue you cannot trust or tune.
- Trusted members are never screened. Spending money to second-guess someone who
  has posted politely for three years is worse than useless.
