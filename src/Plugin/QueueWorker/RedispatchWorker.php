<?php

namespace Drupal\as_webhook_update\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\as_webhook_update\Service\WebhookDispatcherService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Re-dispatches webhook notifications for previously changed nodes, in batches.
 *
 * Items are enqueued by the as_webhook_update:redispatch Drush command and
 * drained under the operator's control via `drush queue:run
 * as_webhook_update_redispatch --items-limit=N`, so a bulk backfill of the
 * downstream sites can be paced instead of firing synchronously on save.
 *
 * No `cron` annotation: this queue only drains when explicitly run, so a
 * backfill never kicks off on its own during a normal cron pass.
 *
 * @QueueWorker(
 *   id = "as_webhook_update_redispatch",
 *   title = @Translation("AS Webhook Update: batched re-dispatch"),
 * )
 */
class RedispatchWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected WebhookDispatcherService $dispatcher,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('as_webhook_update.dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $nid = is_array($data) ? ($data['nid'] ?? NULL) : $data;
    $event = is_array($data) ? ($data['event'] ?? 'update') : 'update';
    if (empty($nid)) {
      return;
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    // The node may have been deleted since it was enqueued; nothing to send.
    if (!$node) {
      return;
    }

    // Force = TRUE so the backfill goes out even while the suppress kill switch
    // is set (e.g. left on to keep ordinary saves quiet during the window).
    $this->dispatcher->dispatch($node, $event, TRUE);
  }

}
