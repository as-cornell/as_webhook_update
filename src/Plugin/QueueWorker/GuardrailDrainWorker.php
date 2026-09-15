<?php

namespace Drupal\as_webhook_update\Plugin\QueueWorker;

/**
 * Drains dispatches the bulk guardrail diverted, slowly, on cron.
 *
 * Separate from as_webhook_update_redispatch on purpose, even though the work is
 * identical, because the two queues have opposite requirements.
 *
 * The redispatch queue holds a **deliberate backfill**: potentially thousands of
 * items an operator enqueued and wants to release at a moment of their choosing.
 * It carries no `cron` annotation precisely so it can never start on its own.
 *
 * This queue holds **ordinary edits that would have been sent immediately** had a
 * bulk operation not been in progress. Nobody chose to defer them. If they only
 * moved when someone remembered to run a drush command, the downstream sites
 * would drift quietly, which is the same silent-divergence problem the guardrail
 * exists to prevent, just slower. So these drain automatically.
 *
 * `time = 30` bounds each cron pass to about 30 seconds of sending, roughly 15
 * items at the observed 2 seconds per call. Enough to clear a diverted bulk edit
 * over a few cron runs, far too slow to constitute a flood.
 *
 * @QueueWorker(
 *   id = "as_webhook_update_guardrail",
 *   title = @Translation("AS Webhook Update: guardrail drain"),
 *   cron = {"time" = 30}
 * )
 */
class GuardrailDrainWorker extends RedispatchWorker {

}
