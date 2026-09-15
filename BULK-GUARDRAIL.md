# Bulk dispatch guardrail

Status: **decision doc**, 2026-09-15. Written before the code, so the threshold
and the exclusions can be argued with by whoever else runs imports.

## The problem

`WebhookDispatcherService` dispatches **synchronously** from
`hook_ENTITY_TYPE_postupdate`. Every saved person node fires its own outbound
HTTP requests, in the request that saved it, before that request can finish.

That is fine for an editor saving one profile. It is not fine for anything that
saves in bulk. Measured on the artsci-people map, 2026-09-15:

| | |
| --- | --- |
| Person nodes in the `people_person` map | 956 |
| Outbound calls one `migrate:import --update` would make | **2,466** |
| Serial time at 2s per call | ~82 minutes |
| Serial time at the module's own 30s cURL timeout | ~20 hours |

For comparison, here is what the live site actually does in normal use, from
`watchdog` over a 24 hour window:

| | |
| --- | --- |
| Dispatches per day | 78 |
| Peak per minute | **8** |
| Peak per 5 minutes | 10 |

So ordinary editing is single digits per minute, and a bulk operation is three
orders of magnitude above it. There is a lot of room to put a line between them.

## What does not solve this

**The A&S affiliation gate does not.** It decides *who* belongs on an A&S site,
not how many requests we are willing to make. Against the same 956 nodes it
filters out **2**, because a people migration only ever touches A&S people in the
first place. It is a correctness filter and should not be confused for a volume
control.

**The suppress kill switch (`as_webhook_update.suppress`) only half does.** It
works, but it is procedural: somebody has to remember to set it before a bulk run
and clear it after. It was `FALSE` on dev, test and live on 2026-09-15, which is
correct for normal operation and exactly why it protects nothing by default.

**`WebhookSuppressionSubscriber` in `as_people_synch_migrate` covers migrations
only.** It holds the switch down for `people_person` and `people_gradfield` and
restores the prior value afterwards. That closes the path we know about. It does
nothing for Views Bulk Operations, a devel generate, a one-off `drush php:eval`
loop, or any automation added later.

## The proposal

Refuse to dispatch in bulk **unless bulk is explicitly allowed**, and when
refusing, **defer rather than drop**.

### Divert, do not block

Past the threshold the dispatcher stops POSTing and **queues the dispatch**
instead. The queue drains slowly on cron, or immediately on demand:

```
drush queue:run as_webhook_update_guardrail
```

This is the whole point of the design. A guardrail that simply blocks recreates
the failure mode this project keeps running into: work disappears and nothing
says so. Diverting turns a synchronous flood into deferred work that still
arrives.

(The original draft sent these to `as_webhook_update_redispatch`. Question 3
below explains why they got a queue of their own instead.)

### Shape

- **Counter** over a rolling 60 second window.
- **Threshold: 25 per minute**, overridable per environment via the State key
  `as_webhook_update.bulk_threshold`. Roughly 3x the observed peak of 8, so it
  cannot trip on real editing, while a bulk operation crosses it in the first
  second.
- **On crossing:** divert to the queue, log **once** rather than per item, and
  set a state flag so the condition is visible.
- **Explicit allowance:** `as_webhook_update.allow_bulk`. When a flood is
  genuinely wanted, that is an affirmative decision someone records, not a
  default nobody knows about.
- **Status report row** naming how many are queued, so a tripped guardrail cannot
  sit unnoticed. A silent guardrail is its own outage.

## Three things the implementation must get right

### 1. Deletes are never diverted

`RedispatchWorker::processItem()` loads the node and returns early if it is gone:

```php
$node = $this->entityTypeManager->getStorage('node')->load($nid);
// The node may have been deleted since it was enqueued; nothing to send.
if (!$node) {
  return;
}
```

So a **queued delete is a dropped delete**, and the downstream copy would survive
forever with nothing to indicate it should not. Deletes must dispatch
synchronously whatever the counter says. This matches how the A&S gate already
treats them, and for the same reason.

### 2. The counter must not cost a database write per dispatch

State API writes are database writes. At 2,466 dispatches that is 2,466 extra
writes, on top of the problem we are trying to solve. Accumulate in a static
within the request and flush once, or use a cache bin, rather than writing State
per call. This is a real decision, not an implementation detail to default.

### 3. Ordering is best-effort

Queued items are sent after live ones. Each payload is a full snapshot rather
than a delta, so this is mostly harmless, but a save followed immediately by
another save could land out of order. Acceptable; worth knowing.

## What this costs

The guardrail lives in **this module**, which is a separate package from the
sites that consume it. Shipping it means a tag, a `composer.lock` bump and a
deploy per site: the same dance as v2.0.10. It cannot be hot-fixed from the
artsci-people repo.

## Questions, resolved 2026-09-15

### 1. Is 25/minute right? Yes as a default, and it is now overridable.

Kept at 25, with a State override, `as_webhook_update.bulk_threshold`, so a site
with heavier editing can raise it without a release.

The choice is easy because **the error costs are wildly asymmetric**. Set it too
low and some legitimate work is deferred by a cron cycle and still arrives. Set
it too high and the flood goes through. So err low.

State rather than config, matching `suppress` and `allow_bulk`, and because
config import is not part of this project's deploy.

### 2. Should tripping also set `suppress`? No.

Suppression stops *every* dispatch, including single edits, and stays set until a
human clears it. That converts a condition which heals itself when the 60 second
window expires into a persistent, silent outage needing intervention: precisely
the failure this guardrail exists to prevent, just wearing a different hat.

Diverting already protects the partner sites, and nothing is lost. There is no
second problem for suppression to solve here.

### 3. Who drains the queue? Cron does, on a queue of its own.

Diverted dispatches go to **`as_webhook_update_guardrail`**, not to
`as_webhook_update_redispatch`, and the new queue carries `cron = {"time" = 30}`.

The two queues look identical and have opposite requirements:

| | `redispatch` | `guardrail` |
| --- | --- | --- |
| What is in it | a deliberate backfill | ordinary edits nobody deferred |
| Who decided to defer | an operator | the guardrail |
| Drains on cron | **no**, by design | **yes**, slowly |

The backfill queue's lack of a `cron` annotation is a deliberate documented
property: thousands of enqueued items must never start moving on their own.
Ordinary edits are the opposite case. If they only moved when somebody
remembered a drush command, downstream would drift quietly, which is the same
silent-divergence problem in slow motion. Hence two queues.

`time = 30` bounds a cron pass to roughly 30 seconds of sending, about 15 items
at the observed 2 seconds per call. Enough to clear a diverted bulk edit over a
few cron runs; far too slow to be a flood. `drush queue:run
as_webhook_update_guardrail` pushes them immediately.

## Visibility

`hook_requirements()` reports three things on the status report, because each one
stops webhooks silently: the kill switch being set, a guardrail backlog (with a
warning past 500, which usually means cron is not running), and
`allow_bulk` being left switched on.
