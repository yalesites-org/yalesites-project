<?php

namespace Drupal\ys_beacon\Plugin\AiFunctionCall;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\PluginManager\AiDataTypeConverterPluginManager;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai\Utility\ContextDefinitionNormalizer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tells the model the current date and time, in a given or default timezone.
 *
 * Reference implementation for ys_beacon's first LLM tool call
 * (yalesites-org/YaleSites-Internal#1146) - the pattern future ys_beacon
 * tools should follow.
 */
#[FunctionCall(
  id: 'ys_beacon:current_date_time',
  function_name: 'ys_beacon_current_date_time',
  name: 'Current Date and Time',
  description: 'Returns the current date and time, in a given IANA timezone (e.g. America/New_York, Asia/Tokyo). Use this whenever the answer depends on knowing today\'s date, the current time, or "now". If the user did not specify a timezone, pass America/New_York.',
  group: 'information_tools',
  context_definitions: [
    'timezone' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Timezone'),
      description: new TranslatableMarkup('An IANA timezone identifier, e.g. America/New_York or Asia/Tokyo. Pass America/New_York if the user did not specify one.'),
      required: TRUE,
      default_value: 'America/New_York',
    ),
  ],
)]
class GetCurrentDateTime extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * Yale's site timezone, used when no timezone is given or it is invalid.
   */
  protected const DEFAULT_TIMEZONE = 'America/New_York';

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ContextDefinitionNormalizer $context_definition_normalizer,
    ?AiDataTypeConverterPluginManager $data_type_converter_manager,
    protected TimeInterface $time,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $context_definition_normalizer, $data_type_converter_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
      $container->get('plugin.manager.ai_data_type_converter'),
      $container->get('datetime.time'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $timezone_name = $this->getContextValue('timezone') ?: self::DEFAULT_TIMEZONE;

    try {
      new \DateTimeZone($timezone_name);
    }
    catch (\Exception) {
      $timezone_name = self::DEFAULT_TIMEZONE;
    }

    $timestamp = $this->time->getCurrentTime();

    $this->setOutput(sprintf(
      '%s (%s), timezone %s.',
      $this->dateFormatter->format($timestamp, 'custom', 'l, F j, Y H:i', $timezone_name),
      $this->dateFormatter->format($timestamp, 'custom', \DateTimeInterface::ATOM, $timezone_name),
      $timezone_name,
    ));
  }

}
