<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Google API settings.
 */
class GoogleApiSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['custom_webform_handlers.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_api_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('custom_webform_handlers.settings');

    $form['google_maps_browser_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google Maps browser API key'),
      '#default_value' => $config->get('google_maps_browser_api_key'),
      '#required' => TRUE,
      '#description' => $this->t('Enter a restricted browser key for Maps JavaScript API and Places API (New). Rotate the previously exposed key before using this module, restrict this key by HTTP referrer, and enable only the required APIs.'),
    ];

    $form['server_api_key_help'] = [
      '#type' => 'item',
      '#title' => $this->t('Google Maps server API key'),
      '#markup' => $this->t('Store the server key outside exported configuration by adding <code>$settings[\'custom_webform_handlers_server_api_key\'] = getenv(\'CUSTOM_WEBFORM_HANDLERS_SERVER_API_KEY\') ?: \'\';</code> to settings.php. Restrict the key by server IP and enable only the required route-distance API.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('custom_webform_handlers.settings')
      ->set('google_maps_browser_api_key', $form_state->getValue('google_maps_browser_api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
