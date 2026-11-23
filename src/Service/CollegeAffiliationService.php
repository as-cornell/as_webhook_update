<?php

namespace Drupal\as_webhook_update\Service;

use Drupal\as_webhook_update\PersonTypeKey;
use Drupal\Core\Entity\EntityInterface;

/**
 * Decides whether a person is affiliated with the College of Arts and Sciences.
 *
 * Every destination this module dispatches to is an A&S property: the A&S site,
 * the A&S department sites, and the A&S media report. The people site it
 * dispatches *from* is university-wide by design, because the HR feed carries
 * the whole academic roster and other colleges are expected to adopt the same
 * model. This service is the gate between the two: a person with no A&S
 * affiliation is not pushed to A&S destinations.
 *
 * A person qualifies on any one of three signals, checked in cost order:
 *
 * 1. `CAS` appears as the unit code of their primary or affiliated college.
 * 2. They hold a department or program from the A&S departments vocabulary
 *    (`departments_and_programs` on artsci-people), which contains only A&S
 *    units, so any term in it is by itself proof of an A&S home. Only that
 *    vocabulary counts: primary department can also hold a unit outside A&S
 *    (from the Cornell directory), which is no evidence of an A&S home.
 *    This is what keeps the
 *    cross-appointed faculty: someone whose college is Human Ecology or Law but
 *    who sits in Psychology or the Institute for Comparative Modernities is
 *    exactly who the A&S directory exists to show.
 * 3. Their person type is a college-level A&S role. College Staff work for
 *    the college itself, so they have no department and the HR feed never
 *    gives them a college value, but they are A&S by definition.
 *
 * The college fields are only ever populated by the HR feed, which covers
 * academic appointments alone. Graduate students, department staff, college
 * staff and advisory council members are entered by hand and carry no college
 * value at all, which is why signal 1 cannot be the whole test.
 */
class CollegeAffiliationService {

  /**
   * Unit code of the College of Arts and Sciences in the `colleges` vocabulary.
   *
   * Matched on the unit code rather than the term ID or name: term IDs differ
   * between environments, and the names are editorial and get retitled.
   */
  const CAS_UNIT_CODE = 'CAS';

  /**
   * College-level person types that are A&S without holding a department.
   */
  const COLLEGE_LEVEL_PERSON_TYPES = [
    'college_staff',
  ];

  /**
   * College reference fields searched for the A&S unit code.
   */
  const COLLEGE_FIELDS = [
    'field_primary_college',
    'field_affiliated_colleges',
  ];

  /**
   * Vocabularies of A&S departments and programs, by site: artsci-people
   * names it departments_and_programs, the A&S and department sites
   * departments_programs.
   */
  const AS_DEPARTMENT_VOCABULARIES = [
    'departments_and_programs',
    'departments_programs',
  ];

  /**
   * Department and program reference fields.
   */
  const DEPARTMENT_FIELDS = [
    'field_primary_department',
    'field_departments_programs',
  ];

  /**
   * Determines whether a person may be dispatched to A&S destinations.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The person node.
   *
   * @return bool
   *   TRUE if the person has an A&S affiliation, FALSE otherwise.
   */
  public function isArtsAndSciences(EntityInterface $entity): bool {
    // A site whose person bundle carries none of these fields gives the gate
    // nothing to judge on. Pass rather than block: silently dropping every
    // webhook is far worse than dispatching one that should have been held.
    if (!$this->hasAnySignalField($entity)) {
      return TRUE;
    }

    return $this->hasCasCollege($entity)
      || $this->hasDepartment($entity)
      || $this->hasCollegeLevelRole($entity);
  }

  /**
   * Person types the A&S directory lists when A&S is their college.
   *
   * Tenure Stream Faculty and RTE Faculty, by key (as-cornell/backlog#12).
   */
  const DIRECTORY_FACULTY_TYPES = [
    'faculty',
    'other_faculty',
  ];

  /**
   * The Lifecycle value that keeps a person in the A&S directory.
   */
  const ACTIVE_LIFECYCLE = 'active';

  /**
   * Decides whether a person belongs in the A&S directory.
   *
   * The A&S directory rule (team decisions of 16, 18 and 19 November 2026,
   * as-cornell/backlog#5). A person belongs on as.cornell.edu, and so in the
   * A&S directory and on its related-people cards, when:
   * - they are Tenure Stream or RTE Faculty and A&S is their primary or an
   *   affiliated college, or they are College Staff; and
   * - their Lifecycle is Active.
   *
   * The rule lives here, on the sending side only: the dispatcher sends the
   * answer as in_as_directory, and the receivers act on it without knowing
   * the rule. It replaces the Include in A&S directory checkbox. Anyone else
   * who clears isArtsAndSciences() still reaches the department sites.
   *
   * A site whose person bundle has no Lifecycle field counts everyone as
   * Active.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The person node.
   *
   * @return bool
   *   TRUE if the person belongs in the A&S directory.
   */
  public function isInAsDirectory(EntityInterface $entity): bool {
    if ($entity->hasField('field_person_lifecycle')) {
      $lifecycle = $entity->get('field_person_lifecycle')->value;
      if ($lifecycle !== NULL && $lifecycle !== self::ACTIVE_LIFECYCLE) {
        return FALSE;
      }
    }

    $type = PersonTypeKey::fromPerson($entity);
    if (in_array($type, self::COLLEGE_LEVEL_PERSON_TYPES, TRUE)) {
      return TRUE;
    }
    return in_array($type, self::DIRECTORY_FACULTY_TYPES, TRUE)
      && $this->hasCasCollege($entity);
  }

  /**
   * Checks whether any field the gate reads exists on the entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The person node.
   *
   * @return bool
   *   TRUE if at least one signal field is present.
   */
  protected function hasAnySignalField(EntityInterface $entity): bool {
    $fields = array_merge(
      self::COLLEGE_FIELDS,
      self::DEPARTMENT_FIELDS,
      ['field_person_type']
    );

    foreach ($fields as $field) {
      if ($entity->hasField($field)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks the college fields for the A&S unit code.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The person node.
   *
   * @return bool
   *   TRUE if A&S is the primary or an affiliated college.
   */
  protected function hasCasCollege(EntityInterface $entity): bool {
    foreach (self::COLLEGE_FIELDS as $field) {
      if (!$entity->hasField($field)) {
        continue;
      }

      foreach ($entity->get($field)->referencedEntities() as $term) {
        if (!$term->hasField('field_unit_code')) {
          continue;
        }
        $code = strtoupper(trim((string) $term->get('field_unit_code')->value));
        if ($code === self::CAS_UNIT_CODE) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Checks for an A&S department or program reference.
   *
   * Resolved through referencedEntities() rather than isEmpty(), so a reference
   * left dangling by a deleted term does not count as evidence of affiliation,
   * and only terms in the A&S vocabulary count.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The person node.
   *
   * @return bool
   *   TRUE if the person holds at least one A&S department or program term.
   */
  protected function hasDepartment(EntityInterface $entity): bool {
    foreach (self::DEPARTMENT_FIELDS as $field) {
      if (!$entity->hasField($field)) {
        continue;
      }
      foreach ($entity->get($field)->referencedEntities() as $term) {
        if (in_array($term->bundle(), self::AS_DEPARTMENT_VOCABULARIES, TRUE)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Checks for a college-level A&S person type.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The person node.
   *
   * @return bool
   *   TRUE if the person type is College Staff.
   */
  protected function hasCollegeLevelRole(EntityInterface $entity): bool {
    $person_type = PersonTypeKey::fromPerson($entity);

    return $person_type !== NULL
      && in_array($person_type, self::COLLEGE_LEVEL_PERSON_TYPES, TRUE);
  }

}
