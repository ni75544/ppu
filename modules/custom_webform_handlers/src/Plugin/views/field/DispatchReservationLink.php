<?php

namespace Drupal\custom_webform_handlers\Plugin\views\field;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Dispatch\Storage\ReservationStoreInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;

/**
 * The ledger identifier, linked to the detail page when one exists.
 *
 * The Dispatch Planner already has a reservation detail page, and this dashboard
 * links to it rather than growing a second one. The link is published for
 * confirmed Reservations only, because that is the only state the detail route
 * serves: DatabaseReservationStore::adminReservation() filters to confirmed in
 * SQL, so the controller answers a Hold and a missing identifier with the same
 * 404 — deliberately, so the page cannot be used to enumerate which identifiers
 * are currently held. Rendering a link for a Hold would advertise a 404 as a
 * page, and rendering one for every row would tell an operator nothing about
 * which rows are actually readable.
 *
 * Route access is then checked as well, rather than assumed from the fact that
 * the reader reached this dashboard. The two are configured in different places
 * — the detail route in custom_webform_handlers.routing.yml, this dashboard in
 * the View's own access options — and if an administrator ever widens one, this
 * column shows a plain identifier instead of a link into a 403. The check is
 * the route's own, so there is no second copy of the detail page's access rule
 * here and no way to reach the detail page that does not go through it.
 *
 * @see \Drupal\custom_webform_handlers\Controller\DispatchPlannerAdminController::reservation()
 * @see \Drupal\custom_webform_handlers\Dispatch\Storage\DatabaseReservationStore::adminReservation()
 */
#[ViewsField("dispatch_reservation_link")]
class DispatchReservationLink extends FieldPluginBase implements CacheableDependencyInterface {

  /**
   * The existing Dispatch Planner detail route.
   */
  private const DETAIL_ROUTE = 'custom_webform_handlers.dispatch_planner_reservation';

  /**
   * {@inheritdoc}
   *
   * The state decides whether a link is published, so it has to be in the
   * SELECT even when an administrator has not added the state column to the
   * display.
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, ?array &$options = NULL) {
    parent::init($view, $display, $options);

    $this->additional_fields['state'] = 'state';
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $id = $this->getValue($values);
    if (!is_numeric($id) || (int) $id <= 0) {
      return '';
    }
    $id = (int) $id;
    $label = (string) $id;

    if ($this->getValue($values, 'state') !== ReservationStoreInterface::STATE_CONFIRMED) {
      return $this->sanitizeValue($label);
    }

    $url = Url::fromRoute(self::DETAIL_ROUTE, ['dispatch_reservation_id' => $id]);
    if (!$url->access()) {
      return $this->sanitizeValue($label);
    }

    return Link::fromTextAndUrl($label, $url)->toRenderable();
  }

  /**
   * {@inheritdoc}
   *
   * Whether a row carries a link depends on the reader's permissions, so a page
   * built for one role must not be served to another.
   */
  public function getCacheContexts(): array {
    return ['user.permissions'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * Freshness belongs to the display, which runs the 'none' cache plugin and so
   * reports a zero max-age: a Hold lapses against a wall clock, and no ledger
   * write invalidates a cache tag.
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

}
