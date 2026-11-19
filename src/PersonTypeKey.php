<?php

namespace Drupal\as_webhook_update;

use Drupal\Core\Entity\EntityInterface;

/**
 * The stable key of a person's type, which routing matches instead of a label.
 *
 * Person types used to be matched by their term name, so a name could not
 * change without breaking routing on every site. The team renamed Faculty and
 * Other Faculty on 19 November 2026 (as-cornell/backlog#12), so each site's
 * person_type terms carry a key in field_person_type_key: faculty,
 * other_faculty, emeritus, graduate_student, department_staff, college_staff.
 * The key travels in the payload beside the label, and receivers match it too.
 *
 * Until a site's terms have the field filled, the key is derived from the
 * label ("Other Faculty" gives other_faculty), which is what the keys were
 * chosen to equal. So fill the keys before renaming a term: a renamed term
 * with no key would derive a new key and stop routing.
 */
final class PersonTypeKey {

  /**
   * The field on person_type terms that holds the key.
   */
  const FIELD = 'field_person_type_key';

  /**
   * Gets the key of a person node's type.
   *
   * @param \Drupal\Core\Entity\EntityInterface $person
   *   The person node.
   *
   * @return string|null
   *   The key, or NULL when the person has no type.
   */
  public static function fromPerson(EntityInterface $person): ?string {
    if (!$person->hasField('field_person_type')) {
      return NULL;
    }
    return self::fromTerm($person->get('field_person_type')->entity);
  }

  /**
   * Gets the key of a person_type term.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $term
   *   The term, or NULL.
   *
   * @return string|null
   *   The key, or NULL when there is no term.
   */
  public static function fromTerm(?EntityInterface $term): ?string {
    if ($term === NULL) {
      return NULL;
    }
    if ($term->hasField(self::FIELD) && !$term->get(self::FIELD)->isEmpty()) {
      return trim($term->get(self::FIELD)->value);
    }
    return self::fromLabel($term->label());
  }

  /**
   * Derives a key from a person type label, for terms with no key yet.
   *
   * @param string|null $label
   *   The label, such as "Other Faculty".
   *
   * @return string|null
   *   The key, such as "other_faculty", or NULL for an empty label.
   */
  public static function fromLabel(?string $label): ?string {
    $key = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $label)), '_');
    return $key === '' ? NULL : $key;
  }

}
