<?php

namespace Drupal\as_webhook_update\ValueObject;

/**
 * Immutable value object representing a webhook destination.
 *
 * This class encapsulates webhook destination information including
 * the URL, type, and schema. It provides type safety and prevents
 * accidental modification of destination data.
 */
class WebhookDestination {

  /**
   * Webhook destination types.
   */
  const TYPE_AS_PEOPLE = 'as_people';
  const TYPE_DEPT_PEOPLE = 'dept_people';
  const TYPE_ARTICLES = 'articles';
  const TYPE_MEDIAREPORT = 'mediareport';

  /**
   * Schema types.
   */
  const SCHEMA_PEOPLE = 'people';
  const SCHEMA_AS = 'as';

  /**
   * The webhook URL.
   *
   * @var string
   */
  private $url;

  /**
   * The destination type.
   *
   * @var string
   */
  private $type;

  /**
   * The schema (people or as).
   *
   * @var string
   */
  private $schema;

  /**
   * Constructs a WebhookDestination object.
   *
   * @param string $url
   *   The webhook URL.
   * @param string $type
   *   The destination type (as_people, dept_people, articles, mediareport).
   * @param string $schema
   *   The schema (people or as).
   */
  public function __construct(string $url, string $type, string $schema) {
    $this->url = $url;
    $this->type = $type;
    $this->schema = $schema;
  }

  /**
   * Gets the webhook URL.
   *
   * @return string
   *   The webhook URL.
   */
  public function getUrl(): string {
    return $this->url;
  }

  /**
   * Gets the destination type.
   *
   * @return string
   *   The destination type.
   */
  public function getType(): string {
    return $this->type;
  }

  /**
   * Gets the schema.
   *
   * @return string
   *   The schema.
   */
  public function getSchema(): string {
    return $this->schema;
  }

  /**
   * Creates a string representation of the destination.
   *
   * @return string
   *   String representation.
   */
  public function __toString(): string {
    return sprintf('%s (%s) - %s', $this->type, $this->schema, $this->url);
  }

}
