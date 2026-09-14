<?php

namespace Drupal\as_webhook_update\Service;

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
 * 2. They hold any department or program reference. The
 *    `departments_and_programs` vocabulary contains only A&S units, so any term
 *    in it is by itself proof of an A&S home. This is what keeps the
 *    cross-appointed faculty: someone whose college is Human Ecology or Law but
 *    who sits in Psychology or the Institute for Comparative Modernities is
 *    exactly who the A&S directory exists to show.
 * 3. Their person type is a college-level A&S role. College Staff and Advisory
 *    Council members work for the college itself, so they have no department
 *    and the HR feed never gives them a college value, but they are A&S by
 *    definition.
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
    'College Staff',
    'Advisory Council',
  ];

  /**
   * College reference fields searched for the A&S unit code.
   */
  const COLLEGE_FIELDS = [
    'field_primary_college',
    'field_affiliated_colleges',
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
   * Checks for any department or program reference.
   *
   * Resolved through referencedEntities() rather than isEmpty(), so a reference
   * left dangling by a deleted term does not count as evidence of affiliation.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The person node.
   *
   * @return bool
   *   TRUE if the person holds at least one department or program term.
   */
  protected function hasDepartment(EntityInterface $entity): bool {
    foreach (self::DEPARTMENT_FIELDS as $field) {
      if (!$entity->hasField($field)) {
        continue;
      }
      if (!empty($entity->get($field)->referencedEntities())) {
        return TRUE;
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
   *   TRUE if the person type is College Staff or Advisory Council.
   */
  protected function hasCollegeLevelRole(EntityInterface $entity): bool {
    if (!$entity->hasField('field_person_type')) {
      return FALSE;
    }

    $person_type = $entity->get('field_person_type')->entity?->label();

    return $person_type !== NULL
      && in_array($person_type, self::COLLEGE_LEVEL_PERSON_TYPES, TRUE);
  }

}
