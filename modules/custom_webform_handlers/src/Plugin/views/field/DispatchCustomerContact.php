<?php

namespace Drupal\custom_webform_handlers\Plugin\views\field;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One contact element, read from the submission a ledger row points at.
 *
 * The ledger stores a submission identifier, not a customer, so the name, phone
 * and email columns are pseudo-fields over submission_id: three columns, three
 * instances of this handler, each told in the Views data which single element to
 * read. That is why they cannot be filtered or sorted — the value is not in this
 * table, and putting it there would mean either a join across
 * webform_submission_data or a second copy of the customer's details, both of
 * which this dashboard was asked not to introduce.
 *
 * Each instance reads exactly one element through
 * WebformSubmissionInterface::getElementData(), never getData(), so a page of
 * reservations publishes three values per row rather than three whole
 * submissions. The submissions themselves are loaded once per page in
 * preRender(), because a per-cell load would be one entity query per row per
 * column.
 *
 * Access is the dispatch permission and nothing else. A per-submission
 * entity-access check was considered and rejected: a role holding
 * 'view dispatch customer contact' but not Webform's own submission permissions
 * would then be shown a blank cell where a phone number exists, which reads as
 * "this customer gave no phone number" — a false statement about the booking,
 * where a withheld column is at least visibly absent. The permission exists for
 * exactly this disclosure; it is the authority here.
 *
 * The element keys live in the Views data because they are the form's, not this
 * class's: full_name, phone_number and email are the contact fieldset of hg, the
 * one Webform in the Dispatch allowlist. A row written by a form that names its
 * elements differently renders this column's empty text — nothing found at that
 * key — rather than a value guessed from a neighbouring element.
 *
 * @see \Drupal\webform\WebformSubmissionInterface::getElementData()
 * @see custom_webform_handlers_views_data()
 */
#[ViewsField("dispatch_customer_contact")]
class DispatchCustomerContact extends DispatchProtectedFieldBase {

  /**
   * The one element's value per submission, keyed by submission identifier.
   *
   * A key present with a NULL value means the submission was looked for and not
   * found, or holds nothing at this element; it is recorded so a missing row is
   * not queried again on the next page of a pager-driven request.
   *
   * @var array<int, mixed>
   */
  private array $values = [];

  /**
   * Constructs the handler.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin identifier.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads the submissions a page of rows points at.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * The submission element this column publishes.
   *
   * @return string
   *   An element key, or '' when the Views data names none — in which case the
   *   column shows nothing at all rather than falling back to a guess.
   */
  private function elementKey(): string {
    $element = $this->definition['dispatch contact element'] ?? '';

    return is_string($element) ? $element : '';
  }

  /**
   * {@inheritdoc}
   *
   * Loads every submission this page points at in one call. Views runs this
   * once per display with the whole result set, before any cell is rendered.
   *
   * @see \Drupal\views\ViewExecutable::render()
   */
  public function preRender(&$values) {
    $element = $this->elementKey();
    if ($element === '') {
      return;
    }

    $ids = [];
    foreach ($values as $row) {
      $id = $this->submissionId($row);
      if ($id !== NULL && !array_key_exists($id, $this->values)) {
        $ids[$id] = $id;
      }
    }
    if ($ids === []) {
      return;
    }

    $submissions = $this->entityTypeManager
      ->getStorage('webform_submission')
      ->loadMultiple($ids);

    foreach ($ids as $id) {
      $submission = $submissions[$id] ?? NULL;
      $this->values[$id] = $submission === NULL
        ? NULL
        : $submission->getElementData($element);
    }
  }

  /**
   * {@inheritdoc}
   *
   * A row with no submission, a submission that no longer exists, and an
   * element holding nothing all render as the empty string, which becomes this
   * column's configured empty text. A Hold taken before checkout has no
   * submission at all, so that is an ordinary row rather than a fault.
   *
   * A non-scalar value renders as empty too. A composite element — an address
   * or a name split into parts — would otherwise be flattened into whatever a
   * string cast happened to produce, and this dashboard is not the place to
   * decide what a customer's address looks like as one line.
   */
  public function render(ResultRow $values) {
    $id = $this->submissionId($values);
    if ($id === NULL) {
      return '';
    }

    $value = $this->values[$id] ?? NULL;
    if (!is_scalar($value)) {
      return '';
    }

    $value = trim((string) $value);

    return $value === '' ? '' : $this->sanitizeValue($value);
  }

  /**
   * The submission one row points at.
   *
   * @param \Drupal\views\ResultRow $row
   *   The row.
   *
   * @return int|null
   *   The identifier, or NULL when the row carries none. Read through
   *   getValue() off the field's own alias, which is submission_id because the
   *   Views data declares it as this column's real field.
   */
  private function submissionId(ResultRow $row): ?int {
    $id = $this->getValue($row);
    if (!is_numeric($id)) {
      return NULL;
    }
    $id = (int) $id;

    return $id > 0 ? $id : NULL;
  }

  /**
   * {@inheritdoc}
   *
   * The rendered value comes from a submission, not from the ledger, so the
   * page has to be invalidated when submissions change. The list tag is the
   * honest answer at the time cache metadata is calculated: which submissions a
   * page will show is not known until the query has run.
   */
  public function getCacheTags(): array {
    return ['webform_submission_list'];
  }

}
