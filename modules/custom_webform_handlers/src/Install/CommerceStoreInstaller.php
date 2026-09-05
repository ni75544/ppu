<?php

namespace Drupal\custom_webform_handlers\Install;

use CommerceGuys\Intl\Exception\UnknownCurrencyException;
use Drupal\commerce_price\CurrencyImporterInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates the one Commerce Store a first install needs, and never a second one.
 *
 * A Store is a content entity, so no amount of configuration packaging can carry
 * it. config/install ships the Dispatch order type, the Dispatch checkout flow, the
 * order fields and the Webform, and a site that has all of them still cannot take a
 * booking: DispatchOrderFactory asks CurrentStore which shop the order belongs to,
 * and refuses the checkout when the answer is NULL. This class is the first-install
 * bootstrap that closes that gap, called once from hook_install().
 *
 * Two things it deliberately does not do.
 *
 * It does not touch a store that already exists. If storage holds one, this is a
 * module being added to a running shop, and the merchant's own name, address and
 * currency outrank anything a module would invent. That is also the "never create a
 * duplicate" rule taken literally: a second store is not a harmless extra row, it is
 * a second answer to the question of which shop an order was placed with.
 *
 * It does not version merchant data. The store it creates is deliberately thin. The
 * timezone comes from the packaged Dispatch resource profile, so public times agree
 * with the planner from the first request rather than drifting until someone notices;
 * the name and the email are read from the site's own settings at install time; and
 * the address carries a country and nothing else. Each is an environment value the
 * owner sets once at /admin/commerce/config/stores, and none of them belongs in Git.
 *
 * @see \Drupal\custom_webform_handlers\Commerce\DispatchOrderFactory
 */
final class CommerceStoreInstaller {

  /**
   * The currency every Dispatch quote, order and payment is denominated in.
   *
   * Matches the price currency the quote calculator and the order item carry. A
   * fresh database has no commerce_currency entity at all — commerce_price ships an
   * importer, not a default — so this is imported before the store can reference it.
   */
  const CURRENCY = 'USD';

  /**
   * The store bundle the created store uses.
   *
   * Shipped by commerce_store's own config/install as
   * commerce_store.commerce_store_type.online, so the bundle exists on a fresh
   * database the moment commerce_store is installed — which this module's
   * dependencies guarantee.
   */
  const STORE_TYPE = 'online';

  /**
   * The country of the address the created store carries.
   *
   * The address field is required and a country is the smallest valid value. It is
   * the operating country of the service, not a merchant address: no street, city or
   * postcode is versioned or invented.
   */
  const COUNTRY = 'US';

  /**
   * Fallback store name, used only when the site itself is unnamed.
   */
  const FALLBACK_NAME = 'Dispatch';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CurrencyImporterInterface $currencyImporter,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Ensures exactly one Store exists, creating one only when there are none.
   *
   * @return string
   *   'created' when this call created the store, 'kept' when one already existed
   *   and was left untouched, or 'failed' when neither could be achieved. The caller
   *   logs the outcome; nothing branches on it, because a failure here must not abort
   *   an install that is otherwise complete.
   */
  public function ensureStore(): string {
    $storage = $this->entityTypeManager->getStorage('commerce_store');
    $existing = (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute();

    if ($existing > 0) {
      // Someone else's shop. Report what is there and change nothing.
      $default = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('is_default', TRUE)
        ->count()
        ->execute();
      if ($default === 0) {
        // Commerce resolves the current store by the default flag, so a site with
        // stores and no default one still refuses every Dispatch checkout. Flagging
        // an existing store would be picking a merchant's shop for them, so this is
        // said out loud instead.
        $this->logger->warning('Dispatch install found @count Commerce store(s) and none flagged default. Flag one at /admin/commerce/config/stores or Dispatch checkout cannot open an order.', ['@count' => $existing]);
      }
      return 'kept';
    }

    if (!$this->ensureCurrency()) {
      return 'failed';
    }

    try {
      $storage->create([
        'type' => self::STORE_TYPE,
        // Explicit rather than left to the owner default callback, which resolves
        // the acting account: a store installed by cron, by Drush or by a config
        // sync would otherwise be owned by whoever happened to trigger it.
        'uid' => 1,
        'name' => $this->siteValue('name', self::FALLBACK_NAME),
        'mail' => $this->siteValue('mail', ''),
        'default_currency' => self::CURRENCY,
        'timezone' => $this->timezone(),
        'address' => ['country_code' => self::COUNTRY],
        // The flag CurrentStore reads. Without it the store exists and resolves to
        // nothing, which is the same refusal as having no store at all.
        'is_default' => TRUE,
      ])->save();
    }
    catch (EntityStorageException $e) {
      $this->logger->error('Dispatch install could not create the Commerce store: @message', ['@message' => $e->getMessage()]);
      return 'failed';
    }

    $this->logger->info('Dispatch install created the default Commerce store, timezone @zone.', ['@zone' => $this->timezone()]);
    return 'created';
  }

  /**
   * Imports the store's currency when the site does not already have it.
   *
   * commerce_price ships definitions and an importer, not currency entities, so a
   * fresh database has none and a store referencing USD would save a dangling
   * reference. The importer is itself idempotent — it returns the existing entity
   * rather than duplicating it — so this is safe on a site that already imported USD
   * for its own reasons.
   *
   * @return bool
   *   TRUE when the currency is present afterwards.
   */
  private function ensureCurrency(): bool {
    try {
      return (bool) $this->currencyImporter->import(self::CURRENCY);
    }
    catch (UnknownCurrencyException | EntityStorageException $e) {
      $this->logger->error('Dispatch install could not import currency @code, so no Commerce store was created: @message', [
        '@code' => self::CURRENCY,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * The store timezone, taken from the packaged Dispatch resource profile.
   *
   * The planner publishes every public time in the Resource's timezone and stores it
   * in UTC. A store on a different timezone would make Commerce render the order and
   * the receipt in one zone while the board and the confirmation quote another, for
   * the same booking. Reading the packaged value keeps the two agreeing on a first
   * install without a second place to configure it.
   */
  private function timezone(): string {
    $zone = $this->configFactory->get('custom_webform_handlers.dispatch')->get('resource.timezone');
    return is_string($zone) && $zone !== '' ? $zone : date_default_timezone_get();
  }

  /**
   * Reads one site setting, falling back when the site has not set it.
   */
  private function siteValue(string $key, string $fallback): string {
    $value = $this->configFactory->get('system.site')->get($key);
    return is_string($value) && $value !== '' ? $value : $fallback;
  }

}
