<?php

namespace Drupal\as_webhook_update\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;

/**
 * Stops a bulk operation firing thousands of synchronous webhooks.
 *
 * Dispatching happens inside the request that saved the entity, so anything
 * saving in bulk pays for every outbound call before it can finish. Measured on
 * artsci-people 2026-09-15: one `migrate:import people_person --update` would
 * make 2,466 calls, about 82 minutes back to back and up to 20 hours against
 * this module's own 30 second cURL timeout. Normal editing on the same site
 * peaks at 8 dispatches a minute.
 *
 * Past a threshold, this **queues the dispatch instead of sending it**. It
 * deliberately does not simply refuse: a guardrail that drops work recreates the
 * silent-loss problem it is meant to prevent. Diverting turns a flood into
 * deferred work that still arrives.
 *
 * The queue drains itself slowly on cron (see GuardrailDrainWorker), because
 * these are ordinary edits nobody chose to defer. To push them out immediately:
 *
 * @code
 *   drush queue:run as_webhook_update_guardrail
 * @endcode
 *
 * See BULK-GUARDRAIL.md for the measurements and the rejected alternatives.
 */
class BulkGuardrailService {

  /**
   * Queue that diverted dispatches are handed to.
   *
   * Deliberately NOT the redispatch queue. That one holds operator-enqueued
   * backfills and has no cron annotation, so it moves only when somebody runs
   * it. The items here are ordinary edits nobody chose to defer, so they drain
   * automatically and slowly. See GuardrailDrainWorker.
   */
  const QUEUE = 'as_webhook_update_guardrail';

  /**
   * Set this TRUE to allow an unlimited synchronous flood.
   *
   * The affirmative allowance: bulk sending is refused unless somebody has
   * explicitly decided otherwise, rather than permitted by a default nobody
   * knows is there.
   */
  const ALLOW_BULK_KEY = 'as_webhook_update.allow_bulk';

  /**
   * Records that the guardrail has diverted something, for the status report.
   */
  const TRIPPED_KEY = 'as_webhook_update.guardrail_tripped';

  /**
   * Cache bin entry holding the rolling counter.
   */
  const COUNTER_CID = 'as_webhook_update:dispatch_window';

  /**
   * Length of the rolling window, in seconds.
   */
  const WINDOW = 60;

  /**
   * Default dispatches allowed within the window before diverting.
   *
   * Observed peak in normal use on artsci-people is 8 a minute, so this is
   * roughly 3x headroom: high enough that real editing cannot reach it, low
   * enough that a bulk operation crosses it within the first second.
   *
   * Override per environment with THRESHOLD_KEY. State rather than config
   * because this is an operational tuning knob for one site, and because config
   * import is not part of this project's deploy.
   *
   * Erring low is cheap and erring high is not: a threshold set too low defers
   * work that is never lost, while one set too high lets the flood through.
   */
  const THRESHOLD = 25;

  /**
   * State key overriding THRESHOLD.
   */
  const THRESHOLD_KEY = 'as_webhook_update.bulk_threshold';

  /**
   * In-request tally, flushed to the cache once rather than written per call.
   *
   * Writing State on every dispatch would add a database write per call, which
   * is the problem this class exists to prevent, at a smaller scale.
   *
   * @var int
   */
  protected int $pending = 0;

  public function __construct(
    protected StateInterface $state,
    protected CacheBackendInterface $cache,
    protected QueueFactory $queueFactory,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Decides whether a dispatch may be sent now, diverting it if not.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being dispatched.
   * @param string $event
   *   The event type: create, update or delete.
   *
   * @return bool
   *   TRUE to send synchronously. FALSE if it has been queued instead, in which
   *   case the caller must not send.
   */
  public function allows(EntityInterface $entity, string $event): bool {
    // Deletes are never diverted. RedispatchWorker loads the node and returns
    // early when it has gone, so a queued delete is a dropped delete and the
    // downstream copy would survive forever with nothing to say it should not.
    // Deletes are also, by nature, never the bulk case.
    if ($event === 'delete') {
      return TRUE;
    }

    if ($this->state->get(self::ALLOW_BULK_KEY, FALSE)) {
      return TRUE;
    }

    // Only nodes can be queued: the worker re-dispatches by node id, so a
    // taxonomy term has nothing to be re-loaded from. Terms are low volume and
    // are allowed through rather than silently dropped.
    if ($entity->getEntityTypeId() !== 'node') {
      return TRUE;
    }

    if ($this->increment() <= $this->threshold()) {
      return TRUE;
    }

    return !$this->divert($entity, $event);
  }

  /**
   * The active threshold for this environment.
   *
   * @return int
   *   Dispatches permitted per window.
   */
  public function threshold(): int {
    $configured = (int) $this->state->get(self::THRESHOLD_KEY, 0);
    return $configured > 0 ? $configured : self::THRESHOLD;
  }

  /**
   * Adds one to the rolling window and returns the new count.
   *
   * @return int
   *   Dispatches seen in the current window, including this one.
   */
  protected function increment(): int {
    $now = time();
    $item = $this->cache->get(self::COUNTER_CID);
    $window = ($item && is_array($item->data)) ? $item->data : ['start' => $now, 'count' => 0];

    // A window older than WINDOW seconds has expired; start a fresh one.
    if (($now - ($window['start'] ?? 0)) >= self::WINDOW) {
      $window = ['start' => $now, 'count' => 0];
      // A new window means whatever tripped last time is over.
      $this->pending = 0;
    }

    $window['count']++;
    $this->cache->set(
      self::COUNTER_CID,
      $window,
      $window['start'] + self::WINDOW
    );

    return (int) $window['count'];
  }

  /**
   * Queues a dispatch instead of sending it.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to re-dispatch later.
   * @param string $event
   *   The event type.
   *
   * @return bool
   *   TRUE if it was queued.
   */
  protected function divert(EntityInterface $entity, string $event): bool {
    $this->queueFactory->get(self::QUEUE)->createItem([
      'nid' => $entity->id(),
      'event' => $event,
    ]);

    $this->pending++;
    $this->state->set(self::TRIPPED_KEY, time());

    // Deliberately does NOT also set the suppress kill switch. Suppression stops
    // every dispatch including single edits, and stays set until a human clears
    // it, which turns a condition that heals itself when the window expires into
    // a persistent silent outage. Diverting already protects the partner sites,
    // and nothing is lost.

    // Logged once per request rather than per item: a 2,466 item diversion
    // should produce one line that a human reads, not 2,466 they scroll past.
    if ($this->pending === 1) {
      $this->logger->warning(
        'Bulk guardrail tripped: more than @threshold webhook dispatches in @window seconds. Further dispatches are being queued to @queue instead of sent. Drain with: drush queue:run @queue --items-limit=50. To allow a synchronous flood deliberately, set @key to 1.',
        [
          '@threshold' => $this->threshold(),
          '@window' => self::WINDOW,
          '@queue' => self::QUEUE,
          '@key' => self::ALLOW_BULK_KEY,
        ]
      );
    }

    return TRUE;
  }

  /**
   * How many items are waiting in the redispatch queue.
   *
   * @return int
   *   Queue depth.
   */
  public function queuedCount(): int {
    return (int) $this->queueFactory->get(self::QUEUE)->numberOfItems();
  }

  /**
   * When the guardrail last diverted something, or 0.
   *
   * @return int
   *   Unix timestamp.
   */
  public function lastTripped(): int {
    return (int) $this->state->get(self::TRIPPED_KEY, 0);
  }

}
