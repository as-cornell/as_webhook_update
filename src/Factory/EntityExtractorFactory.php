<?php

namespace Drupal\as_webhook_update\Factory;

use Drupal\Core\Entity\EntityInterface;
use Drupal\as_webhook_update\DataExtractor\EntityDataExtractorInterface;

/**
 * Factory for creating appropriate entity data extractors.
 *
 * Uses tagged services to automatically discover and select the correct
 * extractor for a given entity based on entity type and bundle.
 */
class EntityExtractorFactory {

  /**
   * Array of available extractors.
   *
   * @var \Drupal\as_webhook_update\DataExtractor\EntityDataExtractorInterface[]
   */
  protected $extractors = [];

  /**
   * Constructs an EntityExtractorFactory object.
   *
   * @param iterable $extractors
   *   Tagged iterator of entity extractors.
   */
  public function __construct(iterable $extractors) {
    $this->extractors = iterator_to_array($extractors);
  }

  /**
   * Gets the appropriate extractor for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to get an extractor for.
   *
   * @return \Drupal\as_webhook_update\DataExtractor\EntityDataExtractorInterface|null
   *   The extractor that supports this entity, or NULL if none found.
   */
  public function getExtractor(EntityInterface $entity): ?EntityDataExtractorInterface {
    foreach ($this->extractors as $extractor) {
      if ($extractor->supports($entity)) {
        return $extractor;
      }
    }

    return NULL;
  }

  /**
   * Gets the media report person extractor.
   *
   * This is used explicitly when we need the simplified person data
   * for media report destinations.
   *
   * @return \Drupal\as_webhook_update\DataExtractor\EntityDataExtractorInterface|null
   *   The media report person extractor or NULL if not found.
   */
  public function getMediaReportPersonExtractor(): ?EntityDataExtractorInterface {
    foreach ($this->extractors as $extractor) {
      if (get_class($extractor) === 'Drupal\as_webhook_update\DataExtractor\MediaReportPersonDataExtractor') {
        return $extractor;
      }
    }

    return NULL;
  }

  /**
   * Checks if an entity is supported by any extractor.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if an extractor exists for this entity, FALSE otherwise.
   */
  public function isSupported(EntityInterface $entity): bool {
    return $this->getExtractor($entity) !== NULL;
  }

}
