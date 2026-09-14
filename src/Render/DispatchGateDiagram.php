<?php

namespace Drupal\as_webhook_update\Render;

use Drupal\Core\Cache\Cache;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\as_webhook_update\Service\CollegeAffiliationService;
use Drupal\as_webhook_update\Service\PersonTypeRoutingService;
use Drupal\as_webhook_update\Service\WebhookDispatcherService;

/**
 * Builds the render array for the dispatch gate diagram.
 *
 * Shown on both the settings form and the module help page so an operator can
 * see, without reading the dispatcher, why a given person is or is not reaching
 * the downstream sites.
 *
 * Every value in the diagram is read from the services that do the real work:
 * the college gate's constants and a live PersonTypeRoutingService for the
 * routing matrix. Nothing here restates the rules, so the picture cannot drift
 * away from the behaviour it describes. Adding a person type to
 * PersonTypeRoutingService adds a row here on the next page load.
 */
class DispatchGateDiagram {

  use StringTranslationTrait;

  /**
   * Builds the diagram.
   *
   * @return array
   *   A render array.
   */
  public function build(): array {
    return [
      '#theme' => 'as_webhook_update_dispatch_gate',
      '#stages' => $this->stages(),
      '#routing' => $this->routing(),
      '#attached' => ['library' => ['as_webhook_update/gate_diagram']],
      // The rules are compiled into the code, so the only thing that can change
      // this markup is a deploy.
      '#cache' => ['max-age' => Cache::PERMANENT],
    ];
  }

  /**
   * Builds the ordered stages a person save passes through.
   *
   * @return array
   *   A list of stage definitions.
   */
  protected function stages(): array {
    $college_fields = implode(', ', CollegeAffiliationService::COLLEGE_FIELDS);
    $department_fields = implode(', ', CollegeAffiliationService::DEPARTMENT_FIELDS);
    $roles = CollegeAffiliationService::COLLEGE_LEVEL_PERSON_TYPES;

    return [
      [
        'label' => $this->t('Kill switch'),
        'question' => $this->t('Is bulk suppression on?'),
        'detail' => $this->t('The state flag @key is set during migrations and backfills so a webhook does not fire on every saved node. The batched re-dispatch queue forces past it; ordinary saves do not.', [
          '@key' => WebhookDispatcherService::SUPPRESS_STATE_KEY,
        ]),
        'stop' => $this->t('Suppressed: nothing is sent.'),
      ],
      [
        'label' => $this->t('Schema'),
        'question' => $this->t('Is this the people site?'),
        'detail' => $this->t('Person records are only ever dispatched from the people schema. A save on the A&S site itself is not echoed back out.'),
        'stop' => $this->t('Other schema: nothing is sent.'),
      ],
      [
        'label' => $this->t('Deletion'),
        'question' => $this->t('Is the event a delete?'),
        'detail' => $this->t('Deletes skip the affiliation gate on purpose. A record removed at the source has to be removed downstream whatever its affiliation was, and by delete time the fields the gate reads may no longer resolve.'),
        'bypass' => $this->t('Delete: sent without further checks.'),
      ],
      [
        'label' => $this->t('Arts and Sciences gate'),
        'question' => $this->t('Does the person have an A&S affiliation?'),
        'detail' => $this->t('The people site holds the university-wide roster, but every destination below is an A&S property. A person qualifies on any one of the three signals.'),
        'signals' => [
          [
            'title' => $this->t('College'),
            'test' => $this->t('Unit code @code appears in @fields.', [
              '@code' => CollegeAffiliationService::CAS_UNIT_CODE,
              '@fields' => $college_fields,
            ]),
            'note' => $this->t('Matched on the unit code, not the term ID or name: IDs differ between environments and the names are editorial.'),
          ],
          [
            'title' => $this->t('Department or program'),
            'test' => $this->t('Any term referenced from @fields.', [
              '@fields' => $department_fields,
            ]),
            'note' => $this->t('The departments and programs vocabulary holds A&S units only, so a term in it is proof of an A&S home on its own. This is what keeps cross-appointed faculty whose college is elsewhere but who sit in an A&S department.'),
          ],
          [
            'title' => $this->t('College-level role'),
            'test' => $this->formatPlural(
              count($roles),
              'Person type is @types.',
              'Person type is one of: @types.',
              ['@types' => implode(', ', $roles)]
            ),
            'note' => $this->t('These people work for the college itself, so they hold no department, and the HR feed never gives them a college value. They are A&S by definition.'),
          ],
        ],
        'stop' => $this->t('No A&S affiliation: nothing is sent, and the skip is logged.'),
      ],
    ];
  }

  /**
   * Builds the routing matrix for people that clear the gate.
   *
   * @return array
   *   A list of rows, each with a label and the destinations it reaches.
   */
  protected function routing(): array {
    $routing = new PersonTypeRoutingService();

    // Every type either service knows about, in a stable order rather than
    // whatever order the three constants happen to concatenate into.
    $types = array_values(array_unique(array_merge(
      PersonTypeRoutingService::AS_PERSON_TYPES,
      PersonTypeRoutingService::DEPT_PERSON_TYPES,
      PersonTypeRoutingService::MEDIAREPORT_PERSON_TYPES
    )));
    sort($types);

    $destinations = [
      'as_people' => $this->t('A&S site'),
      'mediareport' => $this->t('Media report'),
      'dept_people' => $this->t('Department sites'),
    ];

    $rows = [];
    foreach ($types as $type) {
      // Only 'Other Faculty' reads the A&S directory flag, so only it needs the
      // two rows; showing both for every type would imply a distinction that
      // the routing service does not make.
      $variants = [FALSE];
      if ($routing->getDestinations($type, TRUE) !== $routing->getDestinations($type, FALSE)) {
        $variants = [TRUE, FALSE];
      }

      foreach ($variants as $as_directory) {
        $reached = $routing->getDestinations($type, $as_directory);

        $label = $type;
        if (count($variants) > 1) {
          $label = $as_directory
            ? $this->t('@type, in the A&S directory', ['@type' => $type])
            : $this->t('@type, not in the A&S directory', ['@type' => $type]);
        }

        $cells = [];
        foreach ($destinations as $key => $name) {
          $cells[] = [
            'name' => $name,
            'reached' => in_array($key, $reached, TRUE),
          ];
        }

        $rows[] = ['label' => $label, 'cells' => $cells];
      }
    }

    return [
      'headers' => array_values($destinations),
      'rows' => $rows,
    ];
  }

}
