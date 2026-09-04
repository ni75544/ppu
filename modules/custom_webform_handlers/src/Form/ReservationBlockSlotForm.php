<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\custom_webform_handlers\Reservation\ReservationDefinitionProvider;
use Drupal\custom_webform_handlers\Reservation\ReservationException;
use Drupal\custom_webform_handlers\Reservation\ReservationManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Takes one Slot out of service without a visitor behind it.
 *
 * An administrator supplies the same three strings a visitor would, and they are
 * validated the same way: the Webform's own handler configuration decides which
 * times, resources, weekdays, and horizon exist. A blocked Slot then competes for
 * the unique active key exactly as a Hold does, so blocking a Slot somebody
 * already claimed loses on the database key rather than overwriting them.
 */
final class ReservationBlockSlotForm extends FormBase {

  public function __construct(
    private readonly ReservationManagerInterface $reservationManager,
    private readonly ReservationDefinitionProvider $definitionProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.reservation_manager'),
      $container->get('custom_webform_handlers.reservation_definition_provider'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_webform_handlers_reservation_block_slot';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['help'] = [
      '#markup' => '<p>' . $this->t('Blocking a slot makes it unavailable to visitors until an administrator releases it. The slot must be one the Webform actually offers.') . '</p>',
    ];

    $form['webform_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webform machine name'),
      '#required' => TRUE,
      '#maxlength' => 128,
      '#description' => $this->t('The Webform whose reservation settings define this slot.'),
    ];

    $form['date'] = [
      '#type' => 'date',
      '#title' => $this->t('Date'),
      '#required' => TRUE,
      '#description' => $this->t('The local calendar date, in the timezone the handler configures.'),
    ];

    $form['time'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Time'),
      '#required' => TRUE,
      '#maxlength' => 5,
      '#size' => 8,
      '#description' => $this->t('A 24-hour HH:MM value that the Webform offers as an option, for example 14:00.'),
    ];

    $form['resource'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resource'),
      '#maxlength' => 128,
      '#description' => $this->t('Leave empty unless the Webform partitions capacity by resource.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Block slot'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    try {
      $definition = $this->definitionProvider->definition(
        trim((string) $form_state->getValue('webform_id')),
        trim((string) $form_state->getValue('resource')),
      );
      $slot = $this->definitionProvider->slot(
        $definition,
        trim((string) $form_state->getValue('date')),
        trim((string) $form_state->getValue('time')),
      );
    }
    catch (ReservationException $exception) {
      $form_state->setErrorByName(
        $this->fieldForCode($exception->getReservationCode()),
        $this->messageForCode($exception->getReservationCode()),
      );
      return;
    }

    // Carried forward so submitForm() blocks exactly the Slot that was
    // validated, rather than deriving it a second time.
    $form_state->set('reservation_slot', $slot);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $slot = $form_state->get('reservation_slot');

    try {
      $this->reservationManager->block($slot);
    }
    catch (ReservationException $exception) {
      // Losing the unique key means a visitor or another administrator got there
      // first, which is information the administrator needs, not an error page.
      $this->messenger()->addError($this->messageForCode($exception->getReservationCode()));
      $form_state->setRedirect('custom_webform_handlers.reservation_admin');
      return;
    }

    $this->messenger()->addStatus($this->t('Blocked @date at @time.', [
      '@date' => $slot->localDate(),
      '@time' => $slot->localTime(),
    ]));
    $form_state->setRedirect('custom_webform_handlers.reservation_admin');
  }

  /**
   * Maps a failure code to the field an administrator should look at.
   */
  private function fieldForCode(string $code): string {
    return match ($code) {
      ReservationException::INVALID_DATE,
      ReservationException::OUTSIDE_HORIZON,
      ReservationException::WEEKDAY_CLOSED => 'date',
      ReservationException::INVALID_TIME,
      ReservationException::TIME_NONEXISTENT,
      ReservationException::TIME_AMBIGUOUS => 'time',
      ReservationException::INVALID_RESOURCE => 'resource',
      default => 'webform_id',
    };
  }

  /**
   * Maps a failure code to copy that says what to change.
   */
  private function messageForCode(string $code): string {
    return match ($code) {
      ReservationException::SLOT_UNAVAILABLE => (string) $this->t('That slot already has an active hold, reservation, or block.'),
      ReservationException::NOT_CONFIGURED => (string) $this->t('That Webform does not have a usable reservation configuration.'),
      ReservationException::INVALID_DATE => (string) $this->t('Enter a valid date in YYYY-MM-DD format.'),
      ReservationException::INVALID_TIME => (string) $this->t('Enter a time the Webform offers, as a 24-hour HH:MM value.'),
      ReservationException::INVALID_RESOURCE => (string) $this->t('That resource is not one the Webform configures.'),
      ReservationException::OUTSIDE_HORIZON => (string) $this->t('That date is outside the booking window the handler configures.'),
      ReservationException::WEEKDAY_CLOSED => (string) $this->t('That weekday is not open for booking on this Webform.'),
      ReservationException::TIME_NONEXISTENT => (string) $this->t('That local time does not exist on that date, because the clocks move forward.'),
      ReservationException::TIME_AMBIGUOUS => (string) $this->t('That local time happens twice on that date, because the clocks move back.'),
      default => (string) $this->t('That slot could not be blocked.'),
    };
  }

}
