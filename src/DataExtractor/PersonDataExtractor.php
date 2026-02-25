<?php

namespace Drupal\as_webhook_update\DataExtractor;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Extracts webhook data from person nodes.
 *
 * Transforms person node fields into JSON-encoded data suitable for
 * webhook transmission. Handles complex field structures including
 * overview/research data, education, MathJax format detection,
 * and image handling with fallbacks.
 */
class PersonDataExtractor implements EntityDataExtractorInterface {

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a PersonDataExtractor object.
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
   * Intelligently truncates text to a maximum length.
   *
   * Attempts to truncate at sentence boundaries first, then word boundaries.
   *
   * @param string $text
   *   The text to truncate.
   * @param int $max_length
   *   Maximum length in characters (default: 500).
   *
   * @return string
   *   The truncated text with ellipsis if truncated.
   */
  protected function smartTruncate(string $text, int $max_length = 500): string {
    // If already short enough, return as-is.
    if (mb_strlen($text) <= $max_length) {
      return $text;
    }

    // Try to find a sentence boundary within the max length.
    $truncated = mb_substr($text, 0, $max_length);

    // Look for sentence endings: period, exclamation, question mark.
    if (preg_match('/^(.+[.!?])\s/', $truncated, $matches)) {
      return trim($matches[1]);
    }

    // No sentence boundary found, truncate at word boundary.
    $truncated = Unicode::truncate($text, $max_length, TRUE, TRUE);

    return $truncated;
  }

  /**
   * {@inheritdoc}
   */
  public function extract(EntityInterface $entity, string $event): string {
    // Summary - strip all HTML tags and decode entities for plain text.
    if (!empty($entity->field_summary->entity->field_description->value)) {
      $summary = $entity->field_summary->entity->field_description->value;
      // Strip HTML tags and decode HTML entities to get plain text.
      $summary = Html::decodeEntities(strip_tags($summary));
      // Remove extra whitespace.
      $summary = trim(preg_replace('/\s+/', ' ', $summary));
      // Intelligently truncate to max 500 characters.
      $summary = $this->smartTruncate($summary, 500);
    }
    else {
      $summary = 'Directory record for ' . $entity->title->value;
    }

    // Overview and research data.
    $overviewresearch = [];
    if (!empty($entity->field_summary)) {
      foreach ($entity->field_summary as $key => $summary_entity) {
        // Get comma separated list of departments for each overview.
        $deptslist = '';
        if (!empty($summary_entity->entity->field_departments_programs)) {
          foreach ($summary_entity->entity->field_departments_programs as $dept_entity) {
            $deptslist = $deptslist . $dept_entity->entity->name->value . '|';
          }
        }

        // Determine format (MathJax vs full_html).
        $overviewresearchformat = [];
        if (!empty($summary_entity->entity->field_description->format)) {
          $overviewresearchformat[] = $summary_entity->entity->field_description->format;
        }
        if (!empty($summary_entity->entity->field_person_research_focus->format)) {
          $overviewresearchformat[] = $summary_entity->entity->field_person_research_focus->format;
        }

        // If one is mathjax, use mathjax; otherwise use full_html.
        if (in_array('html_with_mathjax', $overviewresearchformat)) {
          $format = 'html_with_mathjax';
        }
        else {
          $format = 'full_html';
        }

        $overviewresearch[$key] = [
          'departments_programs' => explode('|', $deptslist),
          'overview' => $summary_entity->entity->field_description->value,
          'research' => $summary_entity->entity->field_person_research_focus->value,
          'format' => $format,
        ];
      }
    }

    // Mash up body from multiple fields.
    $body = '';
    if (!empty($entity->field_awards_and_honors->value)) {
      $body = $body . '<h3>Awards and Honors</h3>' . trim(preg_replace('/\s\s+/', '', $entity->field_awards_and_honors->value));
    }
    if (!empty($entity->field_professional_experience->value)) {
      $body = $body . '<h3>Professional Experience</h3>' . trim(preg_replace('/\s\s+/', '', $entity->field_professional_experience->value));
    }
    if (!empty($entity->field_affiliations->value)) {
      $body = $body . '<h3>Affiliations</h3>' . trim(preg_replace('/\s\s+/', '', $entity->field_affiliations->value));
    }
    if (!empty($entity->field_person_publications->value)) {
      $body = $body . '<h3>Publications</h3>' . trim(preg_replace('/\s\s+/', '', $entity->field_person_publications->value));
    }
    if (!empty($entity->field_responsibilities->value)) {
      $body = $body . '<h3>Responsibilities</h3>' . trim(preg_replace('/\s\s+/', '', $entity->field_responsibilities->value));
    }

    // Determine body format (MathJax vs full_html).
    $bodyformat = [];
    if (!empty($entity->field_summary->entity->field_description->format)) {
      $bodyformat[] = $entity->field_summary->entity->field_description->format;
    }
    if (!empty($entity->field_summary->entity->field_person_research_focus->format)) {
      $bodyformat[] = $entity->field_summary->entity->field_person_research_focus->format;
    }
    if (!empty($entity->field_awards_and_honors->format)) {
      $bodyformat[] = $entity->field_awards_and_honors->format;
    }
    if (!empty($entity->field_professional_experience->format)) {
      $bodyformat[] = $entity->field_professional_experience->format;
    }
    if (!empty($entity->field_affiliations->format)) {
      $bodyformat[] = $entity->field_affiliations->format;
    }
    if (!empty($entity->field_person_publications->format)) {
      $bodyformat[] = $entity->field_person_publications->format;
    }
    if (!empty($entity->field_responsibilities->format)) {
      $bodyformat[] = $entity->field_responsibilities->format;
    }

    // If one is mathjax, use mathjax; otherwise use full_html.
    if (in_array('html_with_mathjax', $bodyformat)) {
      $final_body_format = 'html_with_mathjax';
    }
    else {
      $final_body_format = 'full_html';
    }

    // Education.
    $education = '';
    if (!empty($entity->field_person_education->value)) {
      $education = trim(preg_replace('/\s\s+/', '', $entity->field_person_education->value));
    }

    // Keywords.
    $keywords = '';
    if (!empty($entity->field_keywords->value)) {
      $keywords = trim(preg_replace('/\s\s+/', '', $entity->field_keywords->value));
    }

    // Portrait image.
    $portrait_image_path = '';
    if (!empty($entity->field_image)) {
      foreach ($entity->field_image as $pireference) {
        if (!empty($pireference->entity->field_media_image->entity)) {
          $portrait_image_path = str_replace('public://', 'public/', $pireference->entity->field_media_image->entity->getFileUri());
          $portrait_image_path = 'https://people.as.cornell.edu/sites/default/files/styles/person_image/' . $portrait_image_path;
        }
      }
    }
    else {
      $portrait_image_path = 'https://people.as.cornell.edu/sites/default/files/default_images/Klarman.jpg';
    }

    // Links.
    $links = [];
    if (!empty($entity->field_links)) {
      foreach ($entity->field_links as $link) {
        $links[] = ['uri' => $link->uri, 'title' => $link->title];
      }
    }

    // Build person data array.
    $data = [
      'event' => $event,
      'type' => 'person',
      'uuid' => $entity->uuid->value,
      'status' => $entity->status->value,
      'uid' => '1',
      'title' => $entity->title->value,
      'netid' => $entity->field_person_netid->value,
      'field_person_last_name' => $entity->field_person_last_name->value,
      'field_job_title' => $entity->field_person_title->value,
      'field_person_type' => $entity->get('field_person_type')->entity?->label(),
      'field_departments_programs' => array_map(fn($term) => $term->label(), $entity->get('field_departments_programs')->referencedEntities()),
      'field_primary_department' => $entity->get('field_primary_department')->entity?->label(),
      'field_portrait_image_path' => $portrait_image_path,
      'field_academic_role' => array_column($entity->get('field_academic_role')->getValue(), 'target_id'),
      'field_research_areas' => array_column($entity->get('field_research_areas')->getValue(), 'target_id'),
      'field_academic_interests' => array_column($entity->get('field_academic_interests')->getValue(), 'target_id'),
      'field_links' => $links,
      'field_summary' => $summary,
      'field_education' => ['format' => $entity->field_person_education->format, 'value' => $education],
      'field_keywords' => ['format' => $entity->field_keywords->format, 'value' => $keywords],
      'field_overview_research' => $overviewresearch,
      'field_body' => ['format' => $final_body_format, 'value' => $body],
      'field_as_directory' => $entity->field_as_directory->value,
      'field_hide_contact_info' => $entity->field_hide_contact_info->value,
      'field_exclude_directory' => $entity->field_exclude_directory->value,
      'field_primary_college' => $entity->get('field_primary_college')->entity?->label(),
      'field_affiliated_colleges' => array_map(fn($term) => $term->label(), $entity->get('field_affiliated_colleges')->referencedEntities()),
    ];

    return json_encode($data, JSON_UNESCAPED_SLASHES);
  }

}
