<?php

namespace Drupal\as_webhook_update\DataExtractor;

use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Extracts simplified webhook data from person nodes for media report.
 *
 * Provides a simplified data structure for media report system,
 * containing only essential person information.
 */
class MediaReportPersonDataExtractor implements EntityDataExtractorInterface {

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a MediaReportPersonDataExtractor object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   */
  public function __construct(RequestStack $request_stack) {
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(EntityInterface $entity): bool {
    // This extractor is called explicitly for media report destinations,
    // so we check if it's a person node.
    return $entity->getEntityTypeId() === 'node' && $entity->bundle() === 'person';
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityType(): string {
    return 'node';
  }

  /**
   * {@inheritdoc}
   */
  public function getBundle(): ?string {
    return 'person';
  }

  /**
   * {@inheritdoc}
   */
  public function extract(EntityInterface $entity, string $event): string {
    $host = $this->requestStack->getCurrentRequest()->getHost();

    // Build simplified media report person data array.
    $data = [
      'event' => $event,
      'type' => 'media_report_person',
      'uuid' => $entity->uuid->value,
      'status' => $entity->status->value,
      'uid' => '1',
      'title' => $entity->title->value,
      'netid' => $entity->field_person_netid->value,
      'field_person_last_name' => $entity->field_person_last_name->value,
      'field_person_type' => $entity->get('field_person_type')->entity?->label(),
      'field_departments_programs' => array_map(fn($term) => $term->label(), $entity->get('field_departments_programs')->referencedEntities()),
      'field_primary_department' => $entity->get('field_primary_department')->entity?->label(),
      'field_link' => 'https://' . $host . '/node/' . $entity->nid->value,
      'field_primary_college' => $entity->get('field_primary_college')->entity?->label(),
      'field_affiliated_colleges' => array_map(fn($term) => $term->label(), $entity->get('field_affiliated_colleges')->referencedEntities()),
    ];

    return json_encode($data, JSON_UNESCAPED_SLASHES);
  }

}
