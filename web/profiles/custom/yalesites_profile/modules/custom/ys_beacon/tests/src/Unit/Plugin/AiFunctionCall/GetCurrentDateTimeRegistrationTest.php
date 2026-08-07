<?php

namespace Drupal\Tests\ys_beacon\Unit\Plugin\AiFunctionCall;

use Drupal\Tests\UnitTestCase;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ys_beacon\Plugin\AiFunctionCall\GetCurrentDateTime;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards against the allow-list and the plugin's own id silently drifting.
 *
 * ToolCallHandlerTest deliberately avoids enabling ys_beacon's own (heavy)
 * module dependency graph, so nothing else asserts that the id configured in
 * ys_beacon.services.yml actually matches this plugin's own attribute - a
 * typo in either place would silently disable the tool (buildToolsInput()
 * returns NULL for an unresolved id) with nothing failing anywhere else.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Plugin\AiFunctionCall\GetCurrentDateTime
 */
class GetCurrentDateTimeRegistrationTest extends UnitTestCase {

  /**
   * The configured allow-list contains this plugin's own attribute id.
   */
  public function testIsOnTheServicesYmlAllowList(): void {
    $reflection = new \ReflectionClass(GetCurrentDateTime::class);
    $attribute = $reflection->getAttributes(FunctionCall::class)[0]->newInstance();

    $path = dirname(__DIR__, 5) . '/ys_beacon.services.yml';
    $this->assertFileExists($path);
    $services = Yaml::parseFile($path);
    $allow_list = $services['services']['ys_beacon.tool_call_handler']['arguments'][2];

    $this->assertContains($attribute->id, $allow_list);
  }

}
