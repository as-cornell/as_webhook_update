<?php

namespace Drupal\as_webhook_update\DataExtractor;

use Drupal\Core\Entity\EntityInterface;

/**
 * Extracts webhook data from taxonomy terms.
 *
 * Handles academic_interests, academic_role, and research_areas vocabularies.
 * Extracts term data including parent relationships and associated departments.
 */
class TaxonomyTermDataExtractor implements EntityDataExtractorInterface {

  /**
   * Supported vocabularies.
   */
  const SUPPORTED_VOCABULARIES = [
    'academic_interests',
    'academic_role',
    'research_areas',
  ];

  /**
   * {@inheritdoc}
   */
  public function supports(EntityInterface $entity): bool {
    if ($entity->getEntityTypeId() !== 'taxonomy_term') {
      return FALSE;
    }

    return in_array($entity->bundle(), self::SUPPORTED_VOCABULARIES);
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityType(): string {
    return 'taxonomy_term';
  }

  /**
   * {@inheritdoc}
   */
  public function getBundle(): ?string {
    // This extractor handles multiple bundles.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function extract(EntityInterface $entity, string $event): string {
    // Get parent term ID.
    $parent_id = NULL;
    $parents = \Drupal::service('entity_type.manager')
      ->getStorage("taxonomy_term")
      ->loadParents($entity->tid->value);
    if (!empty($parents)) {
      $parent = reset($parents);
      $parent_id = $parent->id();
    }

    // Build taxonomy term data array.
    $data = [
      'event' => $event,
      'type' => 'term',
      'vocabulary' => $entity->bundle(),
      'uuid' => $entity->tid->value,
      'status' => $entity->status->value,
      'title' => $entity->name->value,
      'parent' => $parent_id,
      'field_people_tid' => $entity->tid->value,
      'field_departments_programs' => array_map(
        fn($term) => $term->label(),
        $entity->get('field_departments_and_programs')->referencedEntities()
      ),
    ];

    return json_encode($data, JSON_UNESCAPED_SLASHES);
  }

}
