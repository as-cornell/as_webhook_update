<?php

namespace Drupal\as_webhook_update\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\as_webhook_update\Factory\EntityExtractorFactory;

/**
 * Main webhook dispatcher service.
 *
 * Orchestrates the entire webhook notification flow:
 * 1. Receives entity and event type from hooks
 * 2. Determines appropriate data extractor
 * 3. Determines destination URLs based on entity and routing rules
 * 4. Sends webhook notifications via HTTP client
 * 5. Logs all transactions
 */
class WebhookDispatcherService {

  /**
   * The entity extractor factory.
   *
   * @var \Drupal\as_webhook_update\Factory\EntityExtractorFactory
   */
  protected $extractorFactory;

  /**
   * The destination resolver service.
   *
   * @var \Drupal\as_webhook_update\Service\DestinationResolverService
   */
  protected $destinationResolver;

  /**
   * The HTTP client service.
   *
   * @var \Drupal\as_webhook_update\Service\HttpClientService
   */
  protected $httpClient;

  /**
   * The person type routing service.
   *
   * @var \Drupal\as_webhook_update\Service\PersonTypeRoutingService
   */
  protected $personTypeRouting;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a WebhookDispatcherService object.
   *
   * @param \Drupal\as_webhook_update\Factory\EntityExtractorFactory $extractor_factory
   *   The entity extractor factory.
   * @param \Drupal\as_webhook_update\Service\DestinationResolverService $destination_resolver
   *   The destination resolver service.
   * @param \Drupal\as_webhook_update\Service\HttpClientService $http_client
   *   The HTTP client service.
   * @param \Drupal\as_webhook_update\Service\PersonTypeRoutingService $person_type_routing
   *   The person type routing service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    EntityExtractorFactory $extractor_factory,
    DestinationResolverService $destination_resolver,
    HttpClientService $http_client,
    PersonTypeRoutingService $person_type_routing,
    LoggerChannelInterface $logger
  ) {
    $this->extractorFactory = $extractor_factory;
    $this->destinationResolver = $destination_resolver;
    $this->httpClient = $http_client;
    $this->personTypeRouting = $person_type_routing;
    $this->logger = $logger;
  }

  /**
   * Dispatches webhook notifications for an entity event.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that triggered the event.
   * @param string $event
   *   The event type (create, update, delete).
   */
  public function dispatch(EntityInterface $entity, string $event): void {
    // Check if entity is supported.
    if (!$this->extractorFactory->isSupported($entity)) {
      return;
    }

    $bundle = $entity->bundle();
    $entity_type = $entity->getEntityTypeId();

    // Handle based on entity type.
    if ($entity_type === 'node') {
      if ($bundle === 'article') {
        $this->dispatchArticle($entity, $event);
      }
      elseif ($bundle === 'person') {
        $this->dispatchPerson($entity, $event);
      }
    }
    elseif ($entity_type === 'taxonomy_term') {
      $this->dispatchTaxonomyTerm($entity, $event);
    }
  }

  /**
   * Dispatches webhook for article nodes.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The article entity.
   * @param string $event
   *   The event type.
   */
  protected function dispatchArticle(EntityInterface $entity, string $event): void {
    $extractor = $this->extractorFactory->getExtractor($entity);
    if (!$extractor) {
      return;
    }

    $data = $extractor->extract($entity, $event);
    if (empty($data)) {
      return;
    }

    // Articles are sent to the articles destination.
    $destination = $this->destinationResolver->getArticlesDestination();
    if ($destination) {
      $response = $this->httpClient->post($data, $destination->getUrl());

      $this->logger->info(
        'The @event article from @function is nid @nid, HTTP @code to @url',
        [
          '@event' => $event . 'd',
          '@function' => __METHOD__,
          '@nid' => $entity->id(),
          '@code' => $response['http_code'],
          '@url' => $destination->getUrl(),
        ]
      );
    }
  }

  /**
   * Dispatches webhook for person nodes.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The person entity.
   * @param string $event
   *   The event type.
   */
  protected function dispatchPerson(EntityInterface $entity, string $event): void {
    // Only dispatch from people schema.
    if ($this->destinationResolver->getSchema() !== 'people') {
      return;
    }

    $extractor = $this->extractorFactory->getExtractor($entity);
    if (!$extractor) {
      return;
    }

    $data = $extractor->extract($entity, $event);
    if (empty($data)) {
      return;
    }

    // Decode to get person type and as_directory flag.
    $decoded = json_decode($data);
    $person_type = $decoded->field_person_type ?? '';
    $as_directory = $decoded->field_as_directory ?? FALSE;

    // Determine destinations based on person type routing.
    $destination_types = $this->personTypeRouting->getDestinations($person_type, $as_directory);

    $responses = [];

    // Send to AS people webhook.
    if (in_array('as_people', $destination_types)) {
      $destination = $this->destinationResolver->getAsPeopleDestination();
      if ($destination) {
        $response = $this->httpClient->post($data, $destination->getUrl());
        $responses['as'] = $response['http_code'];
      }

      // Also send to media report.
      $mediareport_destination = $this->destinationResolver->getMediaReportDestination();
      if ($mediareport_destination) {
        $mediareport_extractor = $this->extractorFactory->getMediaReportPersonExtractor();
        if ($mediareport_extractor) {
          $mediareport_data = $mediareport_extractor->extract($entity, $event);
          $response = $this->httpClient->post($mediareport_data, $mediareport_destination->getUrl());
          $responses['mediareport'] = $response['http_code'];
        }
      }
    }

    // Send to department people webhook.
    if (in_array('dept_people', $destination_types)) {
      $destination = $this->destinationResolver->getDeptPeopleDestination();
      if ($destination) {
        $response = $this->httpClient->post($data, $destination->getUrl());
        $responses['dept'] = $response['http_code'];
      }
    }

    // Log the transaction.
    $this->logger->info(
      'The @event person from @function is nid @nid, responses: @responses',
      [
        '@event' => $event . 'd',
        '@function' => __METHOD__,
        '@nid' => $entity->id(),
        '@responses' => json_encode($responses),
      ]
    );
  }

  /**
   * Dispatches webhook for taxonomy terms.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The taxonomy term entity.
   * @param string $event
   *   The event type.
   */
  protected function dispatchTaxonomyTerm(EntityInterface $entity, string $event): void {
    $extractor = $this->extractorFactory->getExtractor($entity);
    if (!$extractor) {
      return;
    }

    $data = $extractor->extract($entity, $event);
    if (empty($data)) {
      return;
    }

    // Taxonomy terms are sent to department people destination.
    $destination = $this->destinationResolver->getDeptPeopleDestination();
    if ($destination) {
      $response = $this->httpClient->post($data, $destination->getUrl());

      $this->logger->info(
        'The @event @bundle taxonomy term from @function is @name, tid @tid, HTTP @code',
        [
          '@event' => $event . 'd',
          '@bundle' => $entity->bundle(),
          '@function' => __METHOD__,
          '@name' => $entity->name->value,
          '@tid' => $entity->tid->value,
          '@code' => $response['http_code'],
        ]
      );
    }
  }

}
