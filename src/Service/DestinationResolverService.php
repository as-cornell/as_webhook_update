<?php

namespace Drupal\as_webhook_update\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\as_webhook_update\ValueObject\WebhookDestination;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for resolving webhook destination URLs based on current domain.
 *
 * Maps the current domain to the appropriate webhook listener URLs
 * by reading environment-specific configuration (local, test, production).
 */
class DestinationResolverService {

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The current domain schema information.
   *
   * @var array|null
   */
  protected $domainSchema = NULL;

  /**
   * Constructs a DestinationResolverService object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(RequestStack $request_stack, ConfigFactoryInterface $config_factory) {
    $this->requestStack = $request_stack;
    $this->configFactory = $config_factory;
  }

  /**
   * Gets the current host from the request.
   *
   * @return string
   *   The current host.
   */
  protected function getCurrentHost(): string {
    $request = $this->requestStack->getCurrentRequest();
    return $request ? $request->getHost() : '';
  }

  /**
   * Gets the domain schema information for the current host.
   *
   * @return array
   *   Array containing 'domain', 'schema', and URL keys.
   */
  public function getDomainSchema(): array {
    if ($this->domainSchema !== NULL) {
      return $this->domainSchema;
    }

    $host = $this->getCurrentHost();
    $config = $this->configFactory->get('as_webhook_update.domain_config');

    // Define domain mappings.
    $domain_mappings = [
      // Local (Lando).
      'artsci-people.lndo.site' => ['env' => 'local', 'type' => 'people'],
      'artsci-as.lndo.site' => ['env' => 'local', 'type' => 'as'],
      // Dev (Pantheon).
      'dev-artsci-people.pantheonsite.io' => ['env' => 'dev', 'type' => 'people'],
      'dev-artsci-as.pantheonsite.io' => ['env' => 'dev', 'type' => 'as'],
      // Test (Pantheon).
      'test-artsci-people.pantheonsite.io' => ['env' => 'test', 'type' => 'people'],
      'test-artsci-as.pantheonsite.io' => ['env' => 'test', 'type' => 'as'],
      // Live (Pantheon).
      'live-artsci-people.pantheonsite.io' => ['env' => 'live', 'type' => 'people'],
      'live-artsci-as.pantheonsite.io' => ['env' => 'live', 'type' => 'as'],
      // Production.
      'people.as.cornell.edu' => ['env' => 'production', 'type' => 'people'],
      'as.cornell.edu' => ['env' => 'production', 'type' => 'as'],
    ];

    if (!isset($domain_mappings[$host])) {
      // Unknown domain, return empty schema.
      $this->domainSchema = ['domain' => $host, 'schema' => 'unknown'];
      return $this->domainSchema;
    }

    $mapping = $domain_mappings[$host];
    $env = $mapping['env'];
    $type = $mapping['type'];

    // Get configuration for this environment and type.
    $env_config = $config->get($env . '.' . $type);

    $this->domainSchema = [
      'domain' => $host,
      'schema' => $env_config['schema'] ?? 'unknown',
    ];

    // Add URLs if they exist.
    if (isset($env_config['urls'])) {
      foreach ($env_config['urls'] as $key => $url) {
        $this->domainSchema[$key] = $url;
      }
    }

    return $this->domainSchema;
  }

  /**
   * Gets the schema type (people or as).
   *
   * @return string
   *   The schema type.
   */
  public function getSchema(): string {
    $domain_schema = $this->getDomainSchema();
    return $domain_schema['schema'] ?? 'unknown';
  }

  /**
   * Creates a WebhookDestination object for articles.
   *
   * @return \Drupal\as_webhook_update\ValueObject\WebhookDestination|null
   *   The webhook destination or NULL if not available.
   */
  public function getArticlesDestination(): ?WebhookDestination {
    $domain_schema = $this->getDomainSchema();
    if (isset($domain_schema['articlesurl'])) {
      return new WebhookDestination(
        $domain_schema['articlesurl'],
        WebhookDestination::TYPE_ARTICLES,
        $domain_schema['schema']
      );
    }
    return NULL;
  }

  /**
   * Creates a WebhookDestination object for AS people.
   *
   * @return \Drupal\as_webhook_update\ValueObject\WebhookDestination|null
   *   The webhook destination or NULL if not available.
   */
  public function getAsPeopleDestination(): ?WebhookDestination {
    $domain_schema = $this->getDomainSchema();
    if (isset($domain_schema['aspeopleurl'])) {
      return new WebhookDestination(
        $domain_schema['aspeopleurl'],
        WebhookDestination::TYPE_AS_PEOPLE,
        $domain_schema['schema']
      );
    }
    return NULL;
  }

  /**
   * Creates a WebhookDestination object for department people.
   *
   * @return \Drupal\as_webhook_update\ValueObject\WebhookDestination|null
   *   The webhook destination or NULL if not available.
   */
  public function getDeptPeopleDestination(): ?WebhookDestination {
    $domain_schema = $this->getDomainSchema();
    if (isset($domain_schema['deptpeopleurl'])) {
      return new WebhookDestination(
        $domain_schema['deptpeopleurl'],
        WebhookDestination::TYPE_DEPT_PEOPLE,
        $domain_schema['schema']
      );
    }
    return NULL;
  }

  /**
   * Creates a WebhookDestination object for media report.
   *
   * @return \Drupal\as_webhook_update\ValueObject\WebhookDestination|null
   *   The webhook destination or NULL if not available.
   */
  public function getMediaReportDestination(): ?WebhookDestination {
    $domain_schema = $this->getDomainSchema();
    if (isset($domain_schema['mediareporturl'])) {
      return new WebhookDestination(
        $domain_schema['mediareporturl'],
        WebhookDestination::TYPE_MEDIAREPORT,
        $domain_schema['schema']
      );
    }
    return NULL;
  }

}
