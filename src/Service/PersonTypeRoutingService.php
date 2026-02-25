<?php

namespace Drupal\as_webhook_update\Service;

/**
 * Service for determining person webhook routing based on person type.
 *
 * Encapsulates the business logic for which webhook destinations should
 * receive person data based on the person's type and as_directory flag.
 *
 * Routing rules:
 * - Faculty: AS + MediaReport + Dept
 * - College Staff: AS + MediaReport
 * - Advisory Council: AS + MediaReport
 * - Other Faculty (with as_directory=true): AS + MediaReport + Dept
 * - Other Faculty (with as_directory=false): Dept only
 * - Department Staff: Dept only
 * - Graduate Student: Dept only
 */
class PersonTypeRoutingService {

  /**
   * Person types that should be sent to AS webhook.
   */
  const AS_PERSON_TYPES = [
    'Faculty',
    'College Staff',
    'Advisory Council',
  ];

  /**
   * Person types that should be sent to department webhook.
   */
  const DEPT_PERSON_TYPES = [
    'Faculty',
    'Other Faculty',
    'Department Staff',
    'Graduate Student',
  ];

  /**
   * Person types that should be sent to media report webhook.
   */
  const MEDIAREPORT_PERSON_TYPES = [
    'Faculty',
    'College Staff',
    'Advisory Council',
  ];

  /**
   * Determines if a person should be sent to AS webhook.
   *
   * @param string $person_type
   *   The person type (e.g., 'Faculty', 'College Staff').
   * @param bool $as_directory
   *   Whether the person has as_directory flag set.
   *
   * @return bool
   *   TRUE if should be sent to AS webhook, FALSE otherwise.
   */
  public function shouldSendToAs(string $person_type, bool $as_directory): bool {
    // Regular AS person types.
    if (in_array($person_type, self::AS_PERSON_TYPES)) {
      return TRUE;
    }

    // Other Faculty with as_directory=true.
    if ($person_type === 'Other Faculty' && $as_directory === TRUE) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Determines if a person should be sent to department webhook.
   *
   * @param string $person_type
   *   The person type (e.g., 'Faculty', 'Department Staff').
   * @param bool $as_directory
   *   Whether the person has as_directory flag set (not currently used).
   *
   * @return bool
   *   TRUE if should be sent to department webhook, FALSE otherwise.
   */
  public function shouldSendToDept(string $person_type, bool $as_directory): bool {
    return in_array($person_type, self::DEPT_PERSON_TYPES);
  }

  /**
   * Determines if a person should be sent to media report webhook.
   *
   * @param string $person_type
   *   The person type (e.g., 'Faculty', 'College Staff').
   * @param bool $as_directory
   *   Whether the person has as_directory flag set.
   *
   * @return bool
   *   TRUE if should be sent to media report webhook, FALSE otherwise.
   */
  public function shouldSendToMediaReport(string $person_type, bool $as_directory): bool {
    // Regular media report person types.
    if (in_array($person_type, self::MEDIAREPORT_PERSON_TYPES)) {
      return TRUE;
    }

    // Other Faculty with as_directory=true.
    if ($person_type === 'Other Faculty' && $as_directory === TRUE) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Gets all destinations for a person based on type and flags.
   *
   * @param string $person_type
   *   The person type.
   * @param bool $as_directory
   *   Whether the person has as_directory flag set.
   *
   * @return array
   *   Array of destination types: ['as_people', 'dept_people', 'mediareport'].
   */
  public function getDestinations(string $person_type, bool $as_directory): array {
    $destinations = [];

    if ($this->shouldSendToAs($person_type, $as_directory)) {
      $destinations[] = 'as_people';
    }

    if ($this->shouldSendToDept($person_type, $as_directory)) {
      $destinations[] = 'dept_people';
    }

    if ($this->shouldSendToMediaReport($person_type, $as_directory)) {
      $destinations[] = 'mediareport';
    }

    return $destinations;
  }

}
