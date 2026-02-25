<?php

namespace Drupal\as_webhook_update\DataExtractor;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for entity data extractors.
 *
 * Data extractors implement the Strategy pattern to extract webhook-ready
 * data from different entity types. Each extractor knows how to transform
 * a specific entity type into JSON-encoded data for webhook transmission.
 */
interface EntityDataExtractorInterface {

  /**
   * Checks if this extractor supports the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if this extractor can handle the entity, FALSE otherwise.
   */
  public function supports(EntityInterface $entity): bool;

  /**
   * Extracts webhook data from an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to extract data from.
   * @param string $event
   *   The event type (create, update, delete).
   *
   * @return string
   *   JSON-encoded data ready for webhook transmission.
   */
  public function extract(EntityInterface $entity, string $event): string;

  /**
   * Gets the entity type this extractor handles.
   *
   * @return string
   *   The entity type (e.g., 'node', 'taxonomy_term').
   */
  public function getEntityType(): string;

  /**
   * Gets the bundle this extractor handles.
   *
   * @return string|null
   *   The bundle (e.g., 'article', 'person') or NULL if handles all bundles.
   */
  public function getBundle(): ?string;

}
