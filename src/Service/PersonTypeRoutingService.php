<?php

namespace Drupal\as_webhook_update\Service;

/**
 * Service for determining person webhook routing based on person type.
 *
 * Encapsulates the business logic for which webhook destinations should
 * receive person data, from the person's type and whether they are in the
 * A&S directory.
 *
 * Types are matched by their key (\Drupal\as_webhook_update\PersonTypeKey),
 * never their label, so a type can be renamed on every site without changing
 * routing (as-cornell/backlog#12).
 *
 * "In the A&S directory" is CollegeAffiliationService::isInAsDirectory(): Tenure
 * Stream or RTE Faculty with A&S as their primary or an affiliated college, or
 * College Staff, and Lifecycle Active. It replaced the Include in A&S
 * directory checkbox (as-cornell/backlog#5). Routing rules, by key:
 * - faculty, other_faculty, college_staff in the A&S directory:
 *   AS + MediaReport + Dept
 * - faculty, other_faculty not in the A&S directory: Dept only
 * - college_staff not in the A&S directory: Dept only. They have no
 *   department, so their copy sits on artsci-departments with no department
 *   domain; it is still sent so the receiver takes it off as.cornell.edu
 *   rather than leaving a stale copy there.
 * - department_staff, graduate_student, emeritus: Dept only. Department
 *   sites keep their emeriti; the A&S site and the media report do not list
 *   them, and the receiver keeps them off the as.cornell.edu domain.
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
   * Person types that can be in the A&S directory, and so sent to AS.
   */
  const AS_PERSON_TYPES = [
    'faculty',
    'other_faculty',
    'college_staff',
  ];

  /**
   * Person types that should be sent to department webhook.
   */
  const DEPT_PERSON_TYPES = [
    'faculty',
    'other_faculty',
    'college_staff',
    'department_staff',
    'graduate_student',
    'emeritus',
  ];

  /**
   * Person types that can reach the media report, when in the A&S directory.
   */
  const MEDIAREPORT_PERSON_TYPES = [
    'faculty',
    'other_faculty',
    'college_staff',
  ];

  /**
   * Determines if a person should be sent to AS webhook.
   *
   * @param string $person_type
   *   The person type key (e.g., 'faculty', 'college_staff').
   * @param bool $in_as_directory
   *   Whether the person is in the A&S directory.
   *
   * @return bool
   *   TRUE if should be sent to AS webhook, FALSE otherwise.
   */
  public function shouldSendToAs(string $person_type, bool $in_as_directory): bool {
    return $in_as_directory && in_array($person_type, self::AS_PERSON_TYPES, TRUE);
  }

  /**
   * Determines if a person should be sent to department webhook.
   *
   * @param string $person_type
   *   The person type key (e.g., 'faculty', 'department_staff').
   * @param bool $in_as_directory
   *   Whether the person is in the A&S directory.
   *
   * @return bool
   *   TRUE if should be sent to department webhook, FALSE otherwise.
   */
  public function shouldSendToDept(string $person_type, bool $in_as_directory): bool {
    // The departments site also hosts as.cornell.edu, so it needs every
    // person the A&S site gets as well as its own department types.
    return in_array($person_type, self::DEPT_PERSON_TYPES, TRUE)
      || $this->shouldSendToAs($person_type, $in_as_directory);
  }

  /**
   * Determines if a person should be sent to media report webhook.
   *
   * The team decided on 18 November 2026 that the media report should receive
   * everyone sent to artsci-departments. That waits on a check of what the
   * media report shows (step 4 of the as-cornell/backlog#5 plan); until then
   * it receives the A&S directory, as the A&S site does.
   *
   * @param string $person_type
   *   The person type key (e.g., 'faculty', 'college_staff').
   * @param bool $in_as_directory
   *   Whether the person is in the A&S directory.
   *
   * @return bool
   *   TRUE if should be sent to media report webhook, FALSE otherwise.
   */
  public function shouldSendToMediaReport(string $person_type, bool $in_as_directory): bool {
    return $in_as_directory && in_array($person_type, self::MEDIAREPORT_PERSON_TYPES, TRUE);
  }

  /**
   * Gets all destinations for a person based on type and the directory rule.
   *
   * @param string $person_type
   *   The person type key.
   * @param bool $in_as_directory
   *   Whether the person is in the A&S directory.
   *
   * @return array
   *   Array of destination types: ['as_people', 'dept_people', 'mediareport'].
   */
  public function getDestinations(string $person_type, bool $in_as_directory): array {
    $destinations = [];

    if ($this->shouldSendToAs($person_type, $in_as_directory)) {
      $destinations[] = 'as_people';
    }

    if ($this->shouldSendToDept($person_type, $in_as_directory)) {
      $destinations[] = 'dept_people';
    }

    if ($this->shouldSendToMediaReport($person_type, $in_as_directory)) {
      $destinations[] = 'mediareport';
    }

    return $destinations;
  }

}
