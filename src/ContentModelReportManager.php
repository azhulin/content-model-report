<?php

namespace Drupal\content_model_report;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;

/**
 * The content model report manager.
 */
class ContentModelReportManager {

  /**
   * Contains the content_model_report.settings configuration object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

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
   * The field type plugin manager.
   *
   * @var \Drupal\Core\Field\FieldTypePluginManagerInterface
   */
  protected $fieldTypePluginManager;

  /**
   * Constructs a ContentModelReportManager object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Field\FieldTypePluginManagerInterface $field_type_plugin_manager
   *   The field type plugin manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, EntityTypeBundleInfoInterface $entity_type_bundle_info, EntityFieldManagerInterface $entity_field_manager, FieldTypePluginManagerInterface $field_type_plugin_manager) {
    $this->config = $config_factory->get('content_model_report.settings');
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityFieldManager = $entity_field_manager;
    $this->fieldTypePluginManager = $field_type_plugin_manager;
  }

  /**
   * Returns content model report data.
   *
   * @param string $for_type
   *   (optional) The entity type ID.
   * @param string $for_bundle
   *   (optional) The entity type bundle.
   *
   * @return array
   *   An associative array representing content model data.
   */
  public function data(string $for_type = NULL, string $for_bundle = NULL): array {
    static $data = NULL;
    if (!isset($data)) {
      $data = [];
      $map = $this->getMap($this->config->get('report.entity_bundles'));
      do {
        $needs_update = FALSE;
        foreach ($map as $key => &$process) {
          if (!$process) {
            continue;
          }
          $process = FALSE;
          list($type, $bundle) = explode('.', $key);
          $definition = $this->entityTypeManager->getDefinition($type, FALSE);
          if (!$definition) {
            continue;
          }
          $bundle_info = $this->entityTypeBundleInfo
            ->getBundleInfo($type)[$bundle] ?? NULL;
          if (!$bundle_info) {
            continue;
          }
          isset($data[$type]) || $data[$type] = [
            'id' => $type,
            'label' => $definition->getLabel(),
            'bundles' => [],
          ];
          $references = [];
          $data[$type]['bundles'][$bundle] = [
            'id' => $bundle,
            'label' => $bundle_info['label'],
            'fields' => $this->fieldData($type, $bundle, $references),
          ];
          foreach ($references as $reference) {
            $needs_update = TRUE;
            isset($map[$reference]) || $map[$reference] = TRUE;
          }
        }
      } while ($needs_update);
      $this->sortData($data);
    }
    $result = $data;
    if (isset($for_type)) {
      $result = $result[$for_type] ?? [];
      if (isset($for_bundle)) {
        $result = $result['bundles'][$for_bundle] ?? [];
      }
    }
    return $result;
  }

  /**
   * Returns field data for specified entity type and bundle.
   *
   * @param string $type
   *   The entity type ID.
   * @param string $bundle
   *   The entity type bundle.
   * @param array &$references
   *   (optional) Collected field references keys.
   *
   * @return array
   *   An associative array containing field info.
   */
  function fieldData(string $type, string $bundle, array &$references = []): array {
    $include_base_fields = $this->config->get('report.base_fields');
    $data = [];
    $base_fields = $this->entityFieldManager->getBaseFieldDefinitions($type);
    $fields = $this->entityFieldManager->getFieldDefinitions($type, $bundle);
    $field_types = $this->fieldTypePluginManager->getDefinitions();
    foreach ($fields as $field => $field_info) {
      if (isset($base_fields[$field])
          && !in_array("$type.$field", $include_base_fields)) {
        continue;
      }
      $field_references = [];
      $field_type = $field_info->getType();
      $reference_types = ['entity_reference', 'entity_reference_revisions'];
      if (in_array($field_type, $reference_types)) {
        $settings = $field_info->getSettings();
        $target_type = $settings['target_type'] ?? NULL;
        $target_bundles = $settings['handler_settings']['target_bundles'] ?? [];
        if ($target_type && $target_bundles) {
          foreach ($target_bundles as $target_bundle) {
            $key = "$target_type.$target_bundle";
            $field_references[$key] = $references[$key] = $key;
          }
          sort($field_references);
        }
      }
      $data[$field] = [
        'label' => (string) $field_info->getLabel(),
        'description' => $field_info->getDescription(),
        'type' => $field_type,
        'type_label' => $field_types[$field_type]['label'] ?? '',
        'required' => $field_info->isRequired(),
        'translatable' => $field_info->isTranslatable(),
        'references' => $field_references,
      ];
    }
    return $data;
  }

  /**
   * Returns entity bundles mapping.
   *
   * @param array $entity_bundles
   *   An array of entity bundles.
   *
   * @return array
   *   An associative array representing entity bundles mapping.
   */
  protected function getMap(array $entity_bundles): array {
    $map = [];
    foreach ($entity_bundles as $key) {
      $process = '~' !== $key[0];
      $key = ltrim($key, '~');
      list($type, $bundle) = explode('.', $key);
      if ('*' === $bundle) {
        if ($this->entityTypeManager->getDefinition($type, FALSE)) {
          $bundle_info = $this->entityTypeBundleInfo->getBundleInfo($type);
          foreach (array_keys($bundle_info) as $bundle) {
            $expanded_key = "$type.$bundle";
            isset($map[$expanded_key]) || $map[$expanded_key] = $process;
          }
        }
      }
      else {
        $map[$key] = $process;
      }
    }
    return $map;
  }

  /**
   * Sorts report data structure.
   *
   * @param array &$data
   *   Report data to sort.
   */
  protected function sortData(array &$data) {
    $callback = function ($a, $b) {
      return $a['label'] <=> $b['label'];
    };
    uasort($data, $callback);
    foreach ($data as &$type_data) {
      uasort($type_data['bundles'], $callback);
      foreach ($type_data['bundles'] as &$bundle_data) {
        uasort($bundle_data['fields'], $callback);
      }
    }
  }

}
