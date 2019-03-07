<?php

namespace Drupal\content_model_report\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure content model report settings.
 */
class ContentModelReportSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs a ContentModelReportSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, EntityTypeBundleInfoInterface $entity_type_bundle_info, EntityFieldManagerInterface $entity_field_manager) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'content_model_report_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'content_model_report.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('content_model_report.settings');

    $form['report'] = [
      '#type' => 'details',
      '#title' => $this->t('Report'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $entity_bundles = $config->get('report.entity_bundles') ?? [];
    $form['report']['entity_bundles'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Entity bundles'),
      '#description' => implode('<br/>', [
        $this->t('Specify entity bundles to report using %format format.', [
          '%format' => 'entity_type.bundle',
        ]),
        $this->t('Enter one item per line.'),
        $this->t('Use * character as a bundle wildcard.'),
        $this->t('Use ~ character before the item to exclude entity bundle.'),
        $this->t('Examples: %example1, %example2, %example3.', [
          '%example1' => 'node.*',
          '%example2' => 'taxonomy_term.tags',
          '%example3' => '~media.*',
        ]),
      ]),
      '#default_value' => implode("\n", $entity_bundles),
      '#rows' => max(count($entity_bundles), 5),
      '#required' => TRUE,
    ];

    $form['report']['include_references'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include references'),
      '#description' => $this->t('Automatically include met entity bundles referenced by entity reference and entity reference revisions fields.'),
      '#default_value' => $config->get('report.include_references'),
    ];

    $base_fields = $config->get('report.base_fields') ?? [];
    $form['report']['base_fields'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Base fields'),
      '#description' => implode('<br/>', [
        $this->t('By default all base fields are exluded from report.'),
        $this->t('Specify base fields to include using %format format.', [
          '%format' => 'entity_type.base_field_id',
        ]),
        $this->t('Enter one item per line.'),
        $this->t('Examples: %example1, %example2, %example3.', [
          '%example1' => 'node.title',
          '%example2' => 'taxonomy_term.name',
          '%example3' => 'taxonomy_term.description',
        ]),
      ]),
      '#default_value' => implode("\n", $base_fields),
      '#rows' => max(count($base_fields), 5),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $this->validateEntityBundles($form_state);
    $this->validateBaseFields($form_state);
  }

  /**
   * Validates entity bundles.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function validateEntityBundles(FormStateInterface $form_state) {
    $path = implode('][', $key = ['report', 'entity_bundles']);
    $items = array_filter(
      array_map('trim', explode("\n", $form_state->getValue($key)))
    );
    foreach ($items as $item) {
      if (!preg_match('@^~?[a-z_]+\.(\*|[a-z_]+)$@', $item)) {
        $form_state->setErrorByName($path, $this->t('Invalid item: %item.', [
          '%item' => $item,
        ]));
        break;
      }
      list($type, $bundle) = explode('.', ltrim($item, '~'));
      if (!$this->entityTypeManager->getDefinition($type, FALSE)) {
        $form_state->setErrorByName($path,
          $this->t('Invalid entity type ID: %type.', ['%type' => $type])
        );
        break;
      }
      if ('*' !== $bundle
          && empty($this->entityTypeBundleInfo->getBundleInfo($type)[$bundle])) {
        $form_state->setErrorByName($path,
          $this->t('Invalid %type bundle: %bundle.', [
            '%type' => $type,
            '%bundle' => $bundle,
          ])
        );
      }
    }
    $form_state->setValue($key, array_unique($items));
  }

  /**
   * Validates base fields.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function validateBaseFields(FormStateInterface $form_state) {
    $path = implode('][', $key = ['report', 'base_fields']);
    $items = array_filter(
      array_map('trim', explode("\n", $form_state->getValue($key)))
    );
    foreach ($items as $item) {
      if (!preg_match('@^[a-z_]+\.[a-z_]+$@', $item)) {
        $form_state->setErrorByName($path, $this->t('Invalid item: %item.', [
          '%item' => $item,
        ]));
        break;
      }
      list($type, $field) = explode('.', $item);
      if (!$this->entityTypeManager->getDefinition($type, FALSE)) {
        $form_state->setErrorByName($path,
          $this->t('Invalid entity type ID: %type.', ['%type' => $type])
        );
        break;
      }
      $base_fields = $this->entityFieldManager->getBaseFieldDefinitions($type);
      if (empty($base_fields[$field])) {
        $form_state->setErrorByName($path,
          $this->t('Invalid %type base field ID: %field.', [
            '%type' => $type,
            '%field' => $field,
          ])
        );
      }
    }
    $form_state->setValue($key, array_unique($items));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('content_model_report.settings')
      ->set('report.entity_bundles',
        $form_state->getValue(['report', 'entity_bundles'])
      )
      ->set('report.include_references',
        $form_state->getValue(['report', 'include_references'])
      )
      ->set('report.base_fields',
        $form_state->getValue(['report', 'base_fields'])
      )
      ->save();
    parent::submitForm($form, $form_state);
  }

}
