<?php

namespace Drupal\as_webhook_update\DataExtractor;

use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Extracts webhook data from article nodes.
 *
 * Transforms article node fields into JSON-encoded data suitable for
 * webhook transmission. Handles images, bylines, body text, and
 * related entities.
 */
class ArticleDataExtractor implements EntityDataExtractorInterface {

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs an ArticleDataExtractor object.
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
    return $entity->getEntityTypeId() === 'node' && $entity->bundle() === 'article';
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
    return 'article';
  }

  /**
   * {@inheritdoc}
   */
  public function extract(EntityInterface $entity, string $event): string {
    $host = $this->requestStack->getCurrentRequest()->getHost();
    $title = $entity->title->value;

    // Summary.
    if (!empty($entity->field_summary->value)) {
      $summary = $entity->field_summary->value;
    }
    else {
      $summary = $title;
    }

    // Body.
    $body = '';
    if (!empty($entity->body->value)) {
      $body = $body . trim(str_replace(
        '/sites/default/files/styles/',
        'https://' . $host . '/sites/default/files/styles/',
        preg_replace('/\s\s+/', '', $entity->body->processed)
      ));
    }

    // Images - portrait, landscape, thumbnail.
    $portrait_image_file = '';
    $portrait_image_path = '';
    $portrait_image_alt = '';

    foreach ($entity->field_image as $pireference) {
      if (!empty($pireference->entity->field_media_image->entity)) {
        $portrait_image_file = str_replace('public://', 'public/', $pireference->entity->field_media_image->entity?->getFileUri() ?? '');
        $portrait_image_path = 'https://' . $host . '/sites/default/files/styles/4_5/' . $portrait_image_file;
        $portrait_image_alt = $pireference->entity->field_media_image?->alt;
      }
    }

    // Set default landscape image, overwrite if there's data.
    $landscape_image_path = 'https://' . $host . '/sites/default/files/styles/6_4_large/' . $portrait_image_file;
    $landscape_image_alt = $portrait_image_alt;
    if (!empty($entity->field_landscape_image)) {
      foreach ($entity->field_landscape_image as $lireference) {
        if (!empty($lireference->entity->field_media_image->entity)) {
          $landscape_image_file = str_replace('public://', 'public/', $lireference->entity->field_media_image->entity?->getFileUri() ?? '');
          $landscape_image_path = 'https://' . $host . '/sites/default/files/styles/6_4_large/' . $landscape_image_file;
          $landscape_image_alt = $lireference->entity->field_media_image?->alt;
        }
      }
    }

    // Set default thumbnail image, overwrite if there's data.
    $thumbnail_image_path = 'https://' . $host . '/sites/default/files/styles/1_1_thumbnail/' . $portrait_image_file;
    $thumbnail_image_alt = $portrait_image_alt;
    if (!empty($entity->field_thumbnail_image)) {
      foreach ($entity->field_thumbnail_image as $tireference) {
        if (!empty($tireference->entity->field_media_image->entity)) {
          $thumbnail_image_file = str_replace('public://', 'public/', $tireference->entity->field_media_image->entity?->getFileUri() ?? '');
          $thumbnail_image_path = 'https://' . $host . '/sites/default/files/styles/1_1_thumbnail/' . $thumbnail_image_file;
          $thumbnail_image_alt = $tireference->entity->field_media_image?->alt;
        }
      }
    }

    // Set pano image if there's data
    $pano_image_path = '';
    $pano_image_alt = '';
    if (!empty($entity->field_pano_image)) {
      foreach ($entity->field_pano_image as $pnireference) {
        if (!empty($pnireference->entity->field_media_image->entity)) {
          $pano_image_file = str_replace('public://', 'public/', $pnireference->entity->field_media_image->entity?->getFileUri() ?? '');
          $pano_image_path = 'https://' . $host . '/sites/default/files/styles/pano/' . $pano_image_file;
          $pano_image_alt = $pnireference->entity->field_media_image?->alt;
        }
      }
    }

    // Build article data array.
    $data = [
      'event' => $event,
      'type' => 'article',
      'uuid' => $entity->uuid?->value,
      'status' => $entity->status?->value,
      'uid' => '1',
      'title' => $entity->title?->value,
      'field_bylines' => $entity->get('field_byline_reference')->entity?->label(),
      'field_card_label' => $entity->field_card_label?->value,
      'field_dateline' => $entity->field_dateline?->value,
      'field_media_sources' => $entity->get('field_media_source_reference')->entity?->label(),
      'field_external_media_source' => $entity->field_external_media_source?->value,
      'field_departments_programs' => array_map(fn($term) => $term->label(), $entity->get('field_departments_programs')?->referencedEntities() ?? []),
      'field_article_view_tags' => '',
      'field_related_articles' => array_map(fn($entity) => $entity->uuid(), $entity->get('field_related_articles')->referencedEntities() ?? []),
      'field_related_disciplines' => array_map(fn($term) => $term->label(), $entity->get('field_related_disciplines')?->referencedEntities() ?? [] ),
      'field_related_people' => array_map(fn($e) => $e->get('field_remote_uuid')->value, $entity->get('field_related_people')?->referencedEntities() ?? []),
      'field_portrait_image_path' => $portrait_image_path,
      'field_portrait_image_alt' => $portrait_image_alt,
      'field_landscape_image_path' => $landscape_image_path,
      'field_landscape_image_alt' => $landscape_image_alt,
      'field_thumbnail_image_path' => $thumbnail_image_path,
      'field_thumbnail_image_alt' => $thumbnail_image_alt,
      'field_pano_image_path' => $pano_image_path,
      'field_pano_image_alt' => $pano_image_alt,
      'field_page_summary' => $summary,
      'field_summary' => $summary,
      'field_body' => ['format' => 'full_html', 'value' => $body],
    ];

    return json_encode($data, JSON_UNESCAPED_SLASHES);
  }

}
