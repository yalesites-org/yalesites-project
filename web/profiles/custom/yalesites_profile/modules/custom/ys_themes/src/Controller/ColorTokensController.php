<?php

namespace Drupal\ys_themes\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ys_themes\ColorTokenResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for displaying color tokens.
 */
class ColorTokensController extends ControllerBase {

  /**
   * The color token resolver service.
   *
   * @var \Drupal\ys_themes\ColorTokenResolver
   */
  protected $colorTokenResolver;

  /**
   * Constructs a ColorTokensController.
   *
   * @param \Drupal\ys_themes\ColorTokenResolver $color_token_resolver
   *   The color token resolver service.
   */
  public function __construct(ColorTokenResolver $color_token_resolver) {
    $this->colorTokenResolver = $color_token_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // @phpstan-ignore-next-line
    return new static(
      $container->get('ys_themes.color_token_resolver')
    );
  }

  /**
   * Displays a table of color tokens.
   *
   * @return array
   *   A render array.
   */
  public function colorTable() {
    $themes = $this->colorTokenResolver->getGlobalThemeColors();

    if (empty($themes)) {
      // Get diagnostic information to help troubleshoot.
      $diagnostics = $this->colorTokenResolver->getDiagnostics();

      $message = '<p>' . $this->t('No color tokens found.') . '</p>';
      $message .= '<details><summary>' . $this->t('Troubleshooting Information') . '</summary>';
      $message .= '<table class="diagnostics-table" style="margin-top: 1em; border-collapse: collapse;">';

      foreach ($diagnostics as $key => $value) {
        if (is_bool($value)) {
          $display_value = $value ? 'Yes' : 'No';
        }
        elseif (is_array($value)) {
          $display_value = implode(', ', array_map(function ($item) {
            return is_string($item) ? $item : json_encode($item);
          }, $value));
        }
        elseif (is_null($value)) {
          $display_value = 'NULL';
        }
        else {
          $display_value = (string) $value;
        }
        $message .= '<tr style="border-bottom: 1px solid #ddd;">';
        $message .= '<td style="padding: 0.5em; font-weight: bold;">' . htmlspecialchars($key) . '</td>';
        $message .= '<td style="padding: 0.5em;">' . htmlspecialchars($display_value) . '</td>';
        $message .= '</tr>';
      }

      $message .= '</table>';
      $message .= '<p style="margin-top: 1em;"><strong>' . $this->t('Expected paths:') . '</strong></p>';
      $message .= '<ul>';
      $message .= '<li>YAML: <code>' . htmlspecialchars($diagnostics['yaml_path']) . '</code></li>';
      $message .= '<li>JSON: <code>' . htmlspecialchars($diagnostics['json_path']) . '</code></li>';
      $message .= '</ul>';
      $message .= '</details>';

      return [
        '#markup' => $message,
      ];
    }

    // Build table rows for each theme.
    // Structure matches Twig template:
    // {% for globalTheme, values in _context.globalThemes %}.
    $rows = [];
    foreach ($themes as $theme_id => $theme_data) {
      $theme_label = $theme_data['label'];
      $colors = $theme_data['colors'];

      // Sort colors by slot number.
      uksort($colors, function ($a, $b) {
        // Extract numbers from slot names (e.g., "slot-one" -> 1).
        preg_match('/slot-(\w+)/', $a, $match_a);
        preg_match('/slot-(\w+)/', $b, $match_b);
        $num_a = $this->wordToNumber($match_a[1] ?? '');
        $num_b = $this->wordToNumber($match_b[1] ?? '');
        return $num_a <=> $num_b;
      });

      foreach ($colors as $slot => $color_data) {
        $hex = $color_data['hex'];
        $name = $color_data['name'];
        $token = $color_data['token'];
        // Display CSS variable name like the Twig template:
        // --global-themes-{theme}-colors-{slot}.
        $css_var = $color_data['css_var'] ?? "--global-themes-{$theme_id}-colors-{$slot}";

        // Create color swatch.
        $swatch = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => [
            'style' => 'width: 50px; height: 50px; background-color: ' . $hex . '; border: 1px solid #ccc; display: inline-block;',
            'title' => $hex,
          ],
        ];

        $rows[] = [
          'data' => [
            ['data' => $theme_label, 'class' => ['theme-label']],
            ['data' => ucfirst(str_replace('slot-', '', $slot)), 'class' => ['slot-name']],
            ['data' => $name, 'class' => ['color-name']],
            ['data' => $hex, 'class' => ['hex-code']],
            ['data' => $css_var, 'class' => ['css-var']],
            ['data' => $token, 'class' => ['token-ref']],
            ['data' => $swatch, 'class' => ['color-swatch']],
          ],
        ];
      }
    }

    $build = [
      '#type' => 'table',
      '#header' => [
        $this->t('Theme'),
        $this->t('Slot'),
        $this->t('Color Name'),
        $this->t('Hex Code'),
        $this->t('CSS Variable'),
        $this->t('Token Reference'),
        $this->t('Swatch'),
      ],
      '#rows' => $rows,
      '#attributes' => [
        'class' => ['color-tokens-table'],
      ],
      '#attached' => [
        'library' => ['ys_themes/color_tokens'],
      ],
    ];

    return $build;
  }

  /**
   * Converts word numbers to integers.
   *
   * @param string $word
   *   Word number like "one", "two", etc.
   *
   * @return int
   *   The integer value.
   */
  protected function wordToNumber($word) {
    $numbers = [
      'one' => 1,
      'two' => 2,
      'three' => 3,
      'four' => 4,
      'five' => 5,
      'six' => 6,
      'seven' => 7,
      'eight' => 8,
      'nine' => 9,
      'ten' => 10,
    ];

    return $numbers[strtolower($word)] ?? 999;
  }

}
