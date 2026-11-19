<?php

namespace Drupal\as_webhook_update\Service;

/**
 * Service for determining person webhook routing based on person type.
 *
 * Encapsulates the business logic for which webhook destinations should
 * receive person data based on the person's type and as_directory flag.
 *
 * Types are matched by their key (\Drupal\as_webhook_update\PersonTypeKey),
 * never their label, so a type can be renamed on every site without changing
 * routing (as-cornell/backlog#12).
 *
 * Routing rules, by key:
 * - faculty: AS + MediaReport + Dept
 * - college_staff: AS + MediaReport + Dept
 * - other_faculty (with as_directory=true): AS + MediaReport + Dept
 * - other_faculty (with as_directory=false): Dept only
 * - department_staff: Dept only
 * - graduate_student: Dept only
 * - emeritus: Dept only. Department sites keep their emeriti; the A&S site
 *   and the media report do not list them, and the receiver keeps them off
 *   the as.cornell.edu domain.
 *
 * Everyone sent to AS is also sent to Dept, because artsci-departments hosts
 * the as.cornell.edu domain that replaces artsci-as. Without this, College
 * Staff never reached it, and its migrated pages could not link to them.
 *
 * Advisory Council was removed as a person type on 2026-10-16: its records
 * were deleted everywhere and are no longer published on any site.
 */
class PersonTypeRoutingService {

  /**
   * Person types that should be sent to AS webhook.
   */
  const AS_PERSON_TYPES = [
    'faculty',
    'college_staff',
  ];

  /**
   * Person types that should be sent to department webhook.
   */
  const DEPT_PERSON_TYPES = [
    'faculty',
    'other_faculty',
    'department_staff',
    'graduate_student',
    'emeritus',
  ];

  /**
   * Person types that should be sent to media report webhook.
   */
  const MEDIAREPORT_PERSON_TYPES = [
    'faculty',
    'college_staff',
  ];

  /**
   * The type whose routing depends on the A&S directory flag.
   */
  const DIRECTORY_FLAG_TYPE = 'other_faculty';

  /**
   * Determines if a person should be sent to AS webhook.
   *
   * @param string $person_type
   *   The person type key (e.g., 'faculty', 'college_staff').
   * @param bool $as_directory
   *   Whether the person has as_directory flag set.
   *
   * @return bool
   *   TRUE if should be sent to AS webhook, FALSE otherwise.
   */
  public function shouldSendToAs(string $person_type, bool $as_directory): bool {
    // Regular AS person types.
    if (in_array($person_type, self::AS_PERSON_TYPES, TRUE)) {
      return TRUE;
    }

    // Other Faculty with as_directory=true.
    if ($person_type === self::DIRECTORY_FLAG_TYPE && $as_directory === TRUE) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Determines if a person should be sent to department webhook.
   *
   * @param string $person_type
   *   The person type key (e.g., 'faculty', 'department_staff').
   * @param bool $as_directory
   *   Whether the person has as_directory flag set.
   *
   * @return bool
   *   TRUE if should be sent to department webhook, FALSE otherwise.
   */
  public function shouldSendToDept(string $person_type, bool $as_directory): bool {
    // The departments site also hosts as.cornell.edu, so it needs every
    // person the A&S site gets as well as its own department types.
    return in_array($person_type, self::DEPT_PERSON_TYPES, TRUE)
      || $this->shouldSendToAs($person_type, $as_directory);
  }

  /**
   * Determines if a person should be sent to media report webhook.
   *
   * @param string $person_type
   *   The person type key (e.g., 'faculty', 'college_staff').
   * @param bool $as_directory
   *   Whether the person has as_directory flag set.
   *
   * @return bool
   *   TRUE if should be sent to media report webhook, FALSE otherwise.
   */
  public function shouldSendToMediaReport(string $person_type, bool $as_directory): bool {
    // Regular media report person types.
    if (in_array($person_type, self::MEDIAREPORT_PERSON_TYPES, TRUE)) {
      return TRUE;
    }

    // Other Faculty with as_directory=true.
    if ($person_type === self::DIRECTORY_FLAG_TYPE && $as_directory === TRUE) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Gets all destinations for a person based on type and flags.
   *
   * @param string $person_type
   *   The person type key.
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
