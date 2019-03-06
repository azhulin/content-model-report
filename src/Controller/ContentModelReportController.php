<?php

namespace Drupal\content_model_report\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Provides responses for content model report routes.
 */
class ContentModelReportController extends ControllerBase {

  /**
   * Builds content model report page.
   *
   * @return array
   *   Render array.
   */
  public function page() {
    $build = [];
    $header = [
      $this->t('Name'),
      $this->t('Type'),
      $this->t('References'),
      $this->t('Required'),
      $this->t('Translatable'),
      $this->t('Description'),
    ];
    $data = content_model_report_data();
    foreach ($data as $entity_type => $entity_type_data) {
      $build[$entity_type] = [
        '#type' => 'details',
        '#title' => $this->t('@label (@id)', [
          '@label' => $entity_type_data['label'],
          '@id' => $entity_type_data['id'],
        ]),
        '#open' => TRUE,
      ];
      foreach ($entity_type_data['bundles'] as $bundle => $bundle_data) {
        $rows = [];
        foreach ($bundle_data['fields'] as $field => $field_data) {
          $references = [];
          foreach ($field_data['references'] as $reference) {
            list($ref_entity_type, $ref_bundle) = explode('.', $reference);
            $exists = content_model_report_data($ref_entity_type, $ref_bundle);
            $references[] = $exists ? [
              '#type' => 'link',
              '#title' => $reference,
              '#url' => Url::fromUri(
                'internal:#' . str_replace('.', '-', $reference)
              ),
              '#attributes' => ['class' => ['reference']],
            ] : [
              '#markup' => $reference,
            ];
          }
          $required = $field_data['required'];
          $translatable = $field_data['translatable'];
          $type_label = $field_data['type_label'];
          $rows[] = [
            [
              'data' => [
                '#type' => 'item',
                '#title' => $field_data['label'],
                '#description' => $field,
                '#description_display' => 'after',
              ],
            ],
            [
              'data' => [
                '#type' => 'item',
                '#title' => $type_label ?: $field_data['type'],
                '#description' => $type_label ? $field_data['type'] : '',
                '#description_display' => 'after',
              ],
            ],
            [
              'data' => [
                '#theme' => 'item_list',
                '#items' => $references,
              ],
            ],
            [
              'data' => [
                '#type' => 'item',
                '#markup' => $required ? $this->t('Yes') : $this->t('No'),
              ],
              'class' => [$required ? 'flag-on' : 'flag-off'],
            ],
            [
              'data' => [
                '#type' => 'item',
                '#markup' => $translatable ? $this->t('Yes') : $this->t('No'),
              ],
              'class' => [$translatable ? 'flag-on' : 'flag-off'],
            ],
            ['data' => ['#markup' => $field_data['description']]],
          ];
        }
        $section = &$build[$entity_type][$bundle];
        $section = [
          '#type' => 'details',
          '#title' => $this->t('@label (@id)', [
            '@label' => $bundle_data['label'],
            '@id' => $bundle_data['id'],
          ]),
          '#attributes' => ['id' => "$entity_type-$bundle"],
        ];
        $section['export'] = [
          '#type' => 'link',
          '#title' => $this->t('⇩ Export'),
          '#url' => Url::fromRoute('content_model_report.export', [
            'entity_type' => $entity_type,
            'bundle' => $bundle,
          ]),
          '#attributes' => ['class' => ['export']],
        ];
        $section['table'] = [
          '#theme' => 'table',
          '#header' => $header,
          '#sticky' => TRUE,
          '#rows' => $rows,
        ];
      }
    }
    $build['#attached']['library'][] = 'content_model_report/page';
    return $build;
  }

  /**
   * Exports content model for specific entity type bundle.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The entity bundle.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   Steamed response.
   */
  public function export(string $entity_type, string $bundle) {
    $response = new StreamedResponse();
    $records = content_model_report_data($entity_type, $bundle)['fields'] ?? [];
    $response->setCallback(function () use ($records) {
      $handle = fopen('php://output', 'r+');
      $header = [
        $this->t('Name'),
        $this->t('Type'),
        $this->t('References'),
        $this->t('Required'),
        $this->t('Translatable'),
        $this->t('Description'),
      ];
      fputcsv($handle, $header);
      foreach ($records as $id => $record) {
        $data = [
          implode("\n", [$record['label'], $id]),
          implode("\n", [$record['type_label'], $record['type']]),
          implode("\n", $record['references']),
          $record['required'] ? $this->t('Yes') : $this->t('No'),
          $record['translatable'] ? $this->t('Yes') : $this->t('No'),
          strip_tags($record['description']),
        ];
        fputcsv($handle, $data);
      }
      fclose($handle);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set(
      'Content-Disposition',
      "attachment; filename=\"$entity_type.$bundle.csv\""
    );
    return $response;
  }

}
