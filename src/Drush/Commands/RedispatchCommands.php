<?php

declare(strict_types=1);

namespace Drupal\as_webhook_update\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for batched webhook re-dispatch.
 */
final class RedispatchCommands extends DrushCommands {

  const QUEUE = 'as_webhook_update_redispatch';

  /**
   * Enqueue changed nodes for batched webhook re-dispatch.
   *
   * Collects node ids (from a migration map, a changed-since time, or an
   * explicit list) and pushes them into the as_webhook_update_redispatch queue.
   * Nothing is sent here — drain the queue at your own pace with:
   *   drush queue:run as_webhook_update_redispatch --items-limit=50 --time-limit=60
   * The worker forces dispatch, so it sends even while the suppress kill switch
   * is set.
   */
  #[CLI\Command(name: 'as_webhook_update:redispatch', aliases: ['awredispatch'])]
  #[CLI\Option(name: 'migration', description: 'Enqueue destination nids from this migration map, e.g. people_person.')]
  #[CLI\Option(name: 'changed-since', description: 'Enqueue person nodes changed at/after this time (timestamp or strtotime string, e.g. "-1 day").')]
  #[CLI\Option(name: 'nids', description: 'Comma-separated node ids to enqueue.')]
  #[CLI\Option(name: 'event', description: 'Event type to dispatch (create, update, delete).')]
  #[CLI\Option(name: 'limit', description: 'Cap the number of nodes enqueued (0 = no cap).')]
  #[CLI\Option(name: 'dry-run', description: 'Report how many nodes would be enqueued without queueing them.')]
  #[CLI\Usage(name: 'drush awredispatch --migration=people_person --dry-run', description: 'Count the synced nodes that would be re-dispatched.')]
  #[CLI\Usage(name: 'drush awredispatch --migration=people_person', description: 'Enqueue the synced nodes, then drain with drush queue:run.')]
  public function redispatch(
    array $options = [
      'migration' => NULL,
      'changed-since' => NULL,
      'nids' => NULL,
      'event' => 'update',
      'limit' => 0,
      'dry-run' => FALSE,
    ],
  ): void {
    $database = \Drupal::database();
    $nids = [];

    if (!empty($options['migration'])) {
      $mid = preg_replace('/[^a-z0-9_]/', '', (string) $options['migration']);
      $table = 'migrate_map_' . $mid;
      if (!$database->schema()->tableExists($table)) {
        throw new \RuntimeException(dt('Migration map table @t not found.', ['@t' => $table]));
      }
      $nids = $database->query("SELECT destid1 FROM {" . $table . "} WHERE destid1 IS NOT NULL")->fetchCol();
    }
    elseif (!empty($options['changed-since'])) {
      $since = $options['changed-since'];
      $ts = is_numeric($since) ? (int) $since : strtotime((string) $since);
      if (!$ts) {
        throw new \RuntimeException(dt('Could not parse --changed-since value "@v".', ['@v' => $since]));
      }
      $nids = $database->query(
        "SELECT nid FROM {node_field_data} WHERE type = 'person' AND changed >= :ts",
        [':ts' => $ts]
      )->fetchCol();
    }
    elseif (!empty($options['nids'])) {
      $nids = array_filter(array_map('trim', explode(',', (string) $options['nids'])));
    }
    else {
      throw new \RuntimeException(dt('Provide one of --migration, --changed-since, or --nids.'));
    }

    $nids = array_values(array_unique(array_map('intval', $nids)));
    $limit = (int) $options['limit'];
    if ($limit > 0) {
      $nids = array_slice($nids, 0, $limit);
    }
    $count = count($nids);

    if ($count === 0) {
      $this->logger()->success(dt('No matching nodes to enqueue.'));
      return;
    }

    if ($options['dry-run']) {
      $this->logger()->notice(dt('@count node(s) would be enqueued into @q.', [
        '@count' => $count,
        '@q' => self::QUEUE,
      ]));
      return;
    }

    $event = !empty($options['event']) ? (string) $options['event'] : 'update';
    $queue = \Drupal::queue(self::QUEUE);
    $queue->createQueue();
    foreach ($nids as $nid) {
      $queue->createItem(['nid' => $nid, 'event' => $event]);
    }

    $this->logger()->success(dt('Enqueued @count node(s) into @q.', [
      '@count' => $count,
      '@q' => self::QUEUE,
    ]));
    $this->logger()->notice(dt('Drain in batches: drush queue:run @q --items-limit=50 --time-limit=60 (repeat until empty; check with drush queue:list).', [
      '@q' => self::QUEUE,
    ]));
  }

}
