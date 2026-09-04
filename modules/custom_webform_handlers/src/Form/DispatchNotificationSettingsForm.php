<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchAudience;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchEvent;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotificationText;

/**
 * Administers who is told about a committed planner fact, and in what words.
 *
 * This form owns notification settings and nothing else. It cannot change what the
 * calendar offers, cannot withhold time, and cannot move a booking: every switch here
 * decides whether a message goes out about something that has already committed.
 *
 * Validation is about coherence rather than taste, in the same spirit as the operating
 * profile form. A configuration that passes must be one the queue worker can actually
 * act on — an event that is switched on has a recipient it can reach and a template with
 * both halves filled in. A configuration that fails is refused rather than saved and
 * left to produce silence that looks like success on the board.
 */
final class DispatchNotificationSettingsForm extends ConfigFormBase {

  /**
   * The config object holding notification settings.
   */
  const SETTINGS = 'custom_webform_handlers.notifications';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_webform_handlers_dispatch_notification_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(self::SETTINGS);

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Notifications are sent after a booking, a move, a release or a closure has committed — never during one. Messages are queued and delivered on cron, so a mail server that is slow or down cannot hold up a booking.') . '</p>',
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send dispatch notifications'),
      '#default_value' => (bool) $config->get('enabled'),
      '#description' => $this->t('While this is off nothing is queued at all, so switching it back on will not release a backlog of messages about bookings that have since come and gone.'),
    ];

    $form['events'] = [
      '#type' => 'details',
      '#title' => $this->t('Which facts are announced'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $events = $config->get('events');
    $events = is_array($events) ? $events : [];

    foreach (DispatchEvent::cases() as $event) {
      $form['events'][$event->value] = [
        '#type' => 'checkbox',
        '#title' => DispatchNotificationText::event($event),
        '#default_value' => (bool) ($events[$event->value] ?? FALSE),
        '#description' => DispatchNotificationText::eventPurpose($event),
      ];
    }

    $form['recipients'] = [
      '#type' => 'details',
      '#title' => $this->t('Where notifications go'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['recipients']['staff'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Staff notification address'),
      '#default_value' => (string) $config->get('recipients.staff'),
      '#maxlength' => 255,
      '#description' => $this->t('One address, or several separated by commas. Staff messages carry operational facts about the Resource and the review queue, and never another customer’s detail.'),
    ];
    $form['recipients']['customer_email_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Element holding the customer address'),
      '#default_value' => (string) $config->get('recipients.customer_email_field'),
      '#maxlength' => 128,
      '#description' => $this->t('The Webform element key holding the customer’s own email address. There is deliberately no fallback: if this is empty, or the element holds anything other than a single valid address, no customer message is sent and the board records why.'),
    ];
    $form['recipients']['contact_line'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Contact line shown to customers'),
      '#default_value' => (string) $config->get('recipients.contact_line'),
      '#maxlength' => 255,
      '#description' => $this->t('Substituted as [contact]. How a customer should reach you — a telephone number, an address to reply to, or both.'),
    ];

    $form['templates'] = [
      '#type' => 'details',
      '#title' => $this->t('Message templates'),
      '#open' => FALSE,
      '#tree' => TRUE,
      '#description' => $this->t('Plain text. These placeholders are substituted and no others: [arrival], [arrival_window], [window_date], [date], [timezone], [delivery_mode], [reservation], [superseded], [submission], [webform], [resource], [detail], [contact], [site_name], [event]. There is no placeholder for a customer’s name, address, telephone number or any other answer they gave — that is deliberate, and it is what guarantees no template can put one customer’s detail into any message.'),
    ];

    $templates = $config->get('templates');
    $templates = is_array($templates) ? $templates : [];

    foreach (DispatchEvent::cases() as $event) {
      foreach ($event->audiences() as $audience) {
        $stored = $templates[$event->value][$audience->value] ?? [];
        $stored = is_array($stored) ? $stored : [];

        $form['templates'][$event->value][$audience->value] = [
          '#type' => 'details',
          '#title' => $this->t('@event — @audience', [
            '@event' => DispatchNotificationText::event($event),
            '@audience' => DispatchNotificationText::audience($audience),
          ]),
          '#open' => FALSE,
        ];
        $form['templates'][$event->value][$audience->value]['subject'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Subject'),
          '#default_value' => (string) ($stored['subject'] ?? ''),
          '#maxlength' => 255,
        ];
        $form['templates'][$event->value][$audience->value]['body'] = [
          '#type' => 'textarea',
          '#title' => $this->t('Body'),
          '#default_value' => (string) ($stored['body'] ?? ''),
          '#rows' => 10,
        ];
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * Refuses a configuration that would look switched on and send nothing. Every
   * complaint here names the field that has to change, because "notifications are not
   * arriving" is otherwise diagnosed by reading a queue table.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $staff = trim((string) $form_state->getValue(['recipients', 'staff']));
    foreach ($this->addresses($staff) as $address) {
      if (filter_var($address, FILTER_VALIDATE_EMAIL) === FALSE) {
        $form_state->setErrorByName('recipients][staff', $this->t('“@address” is not a valid email address.', [
          '@address' => $address,
        ]));
        break;
      }
    }

    $element = trim((string) $form_state->getValue(['recipients', 'customer_email_field']));
    if ($element !== '' && preg_match('/^[a-z0-9_]+$/', $element) !== 1) {
      $form_state->setErrorByName('recipients][customer_email_field', $this->t('An element key may contain only lowercase letters, numbers and underscores.'));
    }

    // Only checked when the feature is on. Somebody drafting templates with the whole
    // thing switched off should be able to save half-finished work.
    if (!(bool) $form_state->getValue('enabled')) {
      return;
    }

    $this->validateEnabledEvents($form_state, $staff, $element);
  }

  /**
   * Checks that every switched-on event can actually be delivered.
   *
   * An event with no recipient for one of its audiences, or with half a template, is a
   * configuration that will queue work and then record "not sent" for every item. Better
   * to refuse it here, where the person who caused it is looking at the field.
   */
  private function validateEnabledEvents(FormStateInterface $form_state, string $staff, string $element): void {
    foreach (DispatchEvent::cases() as $event) {
      if (!(bool) $form_state->getValue(['events', $event->value])) {
        continue;
      }

      foreach ($event->audiences() as $audience) {
        if ($audience === DispatchAudience::Staff && $staff === '') {
          $form_state->setErrorByName('recipients][staff', $this->t('“@event” is switched on and writes to staff, so a staff address is required.', [
            '@event' => DispatchNotificationText::event($event),
          ]));
        }

        if ($audience === DispatchAudience::Customer && $element === '') {
          $form_state->setErrorByName('recipients][customer_email_field', $this->t('“@event” is switched on and writes to customers, so the element holding the customer address is required.', [
            '@event' => DispatchNotificationText::event($event),
          ]));
        }

        $subject = trim((string) $form_state->getValue(['templates', $event->value, $audience->value, 'subject']));
        $body = trim((string) $form_state->getValue(['templates', $event->value, $audience->value, 'body']));

        if ($subject === '' || $body === '') {
          $form_state->setErrorByName('templates][' . $event->value . '][' . $audience->value . '][subject', $this->t('“@event” is switched on, so its @audience template needs both a subject and a body.', [
            '@event' => DispatchNotificationText::event($event),
            '@audience' => DispatchNotificationText::audience($audience),
          ]));
        }
      }
    }
  }

  /**
   * Splits a configured recipient string into individual addresses.
   *
   * Commas only, and empty pieces dropped, so a trailing comma or a doubled separator
   * is a typo rather than an error somebody has to hunt for. The same splitting is used
   * when validating and when saving, so what was checked is what is stored.
   *
   * @return array<int, string>
   *   The addresses, in the order they were written.
   */
  private function addresses(string $value): array {
    if (trim($value) === '') {
      return [];
    }

    $addresses = array_map('trim', explode(',', $value));

    return array_values(array_filter($addresses, static fn (string $address): bool => $address !== ''));
  }

  /**
   * {@inheritdoc}
   *
   * Writes only the keys this form owns, and within them only the events and audiences
   * the code recognises. A submitted value for an event this release does not have is
   * dropped rather than stored: config naming a case the enum cannot resolve would be
   * invisible on this form and unreachable by the worker, which is the worst of both.
   *
   * The staff addresses are written back through the same splitter that validated them,
   * so what was checked is what is saved and a stray separator does not survive.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $events = [];
    $templates = [];

    foreach (DispatchEvent::cases() as $event) {
      $events[$event->value] = (bool) $form_state->getValue(['events', $event->value]);

      foreach ($event->audiences() as $audience) {
        $templates[$event->value][$audience->value] = [
          'subject' => trim((string) $form_state->getValue(['templates', $event->value, $audience->value, 'subject'])),
          'body' => trim((string) $form_state->getValue(['templates', $event->value, $audience->value, 'body'])),
        ];
      }
    }

    $this->config(self::SETTINGS)
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('events', $events)
      ->set('recipients', [
        'staff' => implode(', ', $this->addresses((string) $form_state->getValue(['recipients', 'staff']))),
        'customer_email_field' => trim((string) $form_state->getValue(['recipients', 'customer_email_field'])),
        'contact_line' => trim((string) $form_state->getValue(['recipients', 'contact_line'])),
      ])
      ->set('templates', $templates)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
