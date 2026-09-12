<?php

namespace Drupal\Tests\ys_core\Traits;

use Drupal\Component\Annotation\Doctrine\SimpleAnnotationReader;
use Drupal\Core\Block\Annotation\Block;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\ContextHandler;
use Drupal\Core\Plugin\Context\ContextInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;

/**
 * Assertions and fixtures for blocks using LayoutBuilderEntityContextTrait.
 *
 * Shared by every block that prefers the entity Layout Builder is rendering
 * over a node named by the route. Each of those test classes needs the same
 * scaffolding -- a container carrying the services a context-aware plugin
 * reaches for, a context-definition fixture matching the real annotation, a
 * way to put an entity into the block's context, and the two guards for the
 * trait's own behaviour -- so it lives here instead of being copied per block.
 */
trait LayoutBuilderEntityContextTestTrait {

  /**
   * Sets up the container a context-aware block plugin needs.
   *
   * The @Translation inside the plugin annotation renders through
   * string_translation, and BlockBase's configuration form reaches for
   * context.handler once the plugin declares a context.
   */
  protected function setUpLayoutBuilderEntityContextContainer(): void {
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('context.handler', new ContextHandler());
    \Drupal::setContainer($container);
  }

  /**
   * Context definitions fixture matching the block's real annotation.
   *
   * @param string $class
   *   The block plugin class under test.
   *
   * @return array
   *   A context_definitions array for the plugin definition.
   */
  protected function layoutBuilderEntityContextDefinitions(string $class): array {
    return [$class::ENTITY_CONTEXT => new ContextDefinition('entity', NULL, FALSE)];
  }

  /**
   * Puts an entity into the block's Layout Builder entity context.
   *
   * @param \Drupal\Core\Plugin\ContextAwarePluginInterface $block
   *   The block plugin.
   * @param mixed $value
   *   The context value, normally a node.
   */
  protected function setRenderedEntity(ContextAwarePluginInterface $block, $value): void {
    $context = $this->createMock(ContextInterface::class);
    $context->method('getContextValue')->willReturn($value);
    // A context-aware plugin folds its contexts' cacheability into its own,
    // so an unstubbed mock would return NULL where core expects arrays.
    $context->method('getCacheTags')->willReturn([]);
    $context->method('getCacheContexts')->willReturn([]);
    $context->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $block->setContext($block::ENTITY_CONTEXT, $context);
  }

  /**
   * Asserts the real annotation declares the slot Layout Builder publishes.
   *
   * This is the guard for the whole approach, so it deliberately reads the
   * real @Block annotation through the same annotation reader plugin discovery
   * uses, rather than the hand-built definition the test passes in --
   * asserting the fixture would only prove the fixture. Renaming the
   * annotation's slot to "entity" would still resolve while rendering but NOT
   * during Layout Builder preview, where OverridesSectionStorage unsets
   * "entity", and would put back the need to rewrite the stored
   * context_mapping of every node with an overridden layout. Optional is
   * asserted too: a required slot would throw where no entity is offered
   * instead of degrading to the route.
   *
   * @param string $class
   *   The block plugin class under test.
   */
  protected function assertDeclaresLayoutBuilderEntitySlot(string $class): void {
    $reader = new SimpleAnnotationReader();
    $reader->addNamespace('Drupal\Core\Block\Annotation');
    $reader->addNamespace('Drupal\Core\Annotation');

    $annotation = $reader->getClassAnnotation(new \ReflectionClass($class), Block::class);
    $definition = $annotation->get();

    $this->assertSame('layout_builder.entity', $class::ENTITY_CONTEXT);
    $this->assertArrayHasKey($class::ENTITY_CONTEXT, $definition['context_definitions']);
    $this->assertFalse($definition['context_definitions'][$class::ENTITY_CONTEXT]->isRequired());
  }

  /**
   * Asserts the context-assignment select is kept off the editor's form.
   *
   * BlockBase::buildConfigurationForm() sets $form['context_mapping'] for any
   * plugin declaring a context -- always, even when it resolves to an empty
   * element -- so this fails if the trait's override stops removing it.
   * Editors open these forms routinely, and choosing a route-derived entity
   * context there would store a context_mapping that reinstates the
   * indexed-wrong-title bug on that page alone.
   *
   * @param \Drupal\Core\Block\BlockPluginInterface $block
   *   The block plugin.
   */
  protected function assertNoContextAssignmentSelect(BlockPluginInterface $block): void {
    $block->setStringTranslation($this->getStringTranslationStub());

    $form = $block->buildConfigurationForm([], new FormState());

    $this->assertArrayNotHasKey('context_mapping', $form);
  }

  /**
   * Data provider of context values that must not stand in for the node.
   *
   * Layout Builder hands over whatever entity the display belongs to, so the
   * context is not guaranteed to hold a node -- the defaults layout screen
   * passes a generated sample entity of the display's own type. The slot is
   * also declared as a generic entity, so the consuming block's guard is what
   * keeps a non-node out of its node handling.
   *
   * @return array<string, array{mixed}>
   *   Test cases of a context value.
   */
  public function providerNonNodeContextValues(): array {
    return [
      'an entity that is not a node' => [$this->createMock(EntityInterface::class)],
      'no entity at all' => ['not-an-entity'],
    ];
  }

}
