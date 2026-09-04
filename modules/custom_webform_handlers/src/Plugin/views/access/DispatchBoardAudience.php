<?php

namespace Drupal\custom_webform_handlers\Plugin\views\access;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsAccess;
use Drupal\views\Plugin\views\access\AccessPluginBase;
use Symfony\Component\Routing\Route;

/**
 * Grants a Views display to the audience that already reads the Dispatch board.
 *
 * This plugin exists because core's "perm" plugin holds exactly one permission
 * name. The Dispatch board is readable by either of two permissions, and its
 * routes say so with Drupal's OR syntax: 'a+b'. Putting that same string into
 * the core plugin looks like it works and does not: the string is written
 * verbatim into the route's _permission requirement, where the router does parse
 * '+' as OR and lets the request through — but the plugin's own access() check,
 * which Views runs again while rendering, asks hasPermission() for a permission
 * literally named 'a+b'. No such permission is ever granted, so the check fails
 * for every account except user 1, who is granted everything by definition.
 *
 * The failure is silent and worth naming, because it is the one an operator
 * would report as "the page is blank": the route allows the request, the title
 * renders, and the view element is dropped from the page with no message. A
 * dashboard that renders for the administrator who installed it and for nobody
 * else is worse than one that refuses honestly.
 *
 * So the OR is evaluated here as a real OR, over real permissions, and the route
 * requirement is still written in the '+' form the rest of the Dispatch Planner
 * uses. The audience is fixed rather than configurable: it is not a setting, it
 * is the statement that this dashboard is readable by exactly whoever may
 * already read the board it sits beside.
 *
 * @see \Drupal\user\Plugin\views\access\Permission
 * @see custom_webform_handlers.routing.yml
 */
#[ViewsAccess(
  id: 'dispatch_board_audience',
  title: new TranslatableMarkup('Dispatch board audience'),
  help: new TranslatableMarkup('Access is granted to accounts that may already read the Dispatch Planner board: either "view dispatch board" or "administer dispatch planner".'),
)]
class DispatchBoardAudience extends AccessPluginBase implements CacheableDependencyInterface {

  /**
   * The permissions that read the Dispatch board, any one of which suffices.
   *
   * Kept identical to the _permission requirement on the board's own routes. A
   * change here without the matching change there would hand this dashboard a
   * different audience than the board, which is the confusion this whole plugin
   * is meant to prevent.
   */
  public const AUDIENCE = [
    'view dispatch board',
    'administer dispatch planner',
  ];

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account) {
    foreach (self::AUDIENCE as $permission) {
      if ($account->hasPermission($permission)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterRouteDefinition(Route $route) {
    // '+' is OR and ',' is AND. Only the OR form is correct here: a comma would
    // demand both permissions and lock out every non-administrator.
    $route->setRequirement('_permission', implode('+', self::AUDIENCE));
  }

  /**
   * {@inheritdoc}
   */
  public function summaryTitle() {
    return $this->t('Dispatch board audience');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // What this plugin answers depends on the account's permissions and nothing
    // else, so a render cached under one permission set must not be served to
    // another.
    return ['user.permissions'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return [];
  }

}
