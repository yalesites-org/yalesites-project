<?php

namespace Drupal\ys_contoso_chat\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_search\Plugin\AiFunctionCall\RagTool;
use Drupal\ys_contoso_chat\Service\CitationStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * RAG search that labels results with citation markers for the chat UI.
 *
 * Extends the contrib RAG tool so the LLM sees each search result tagged with
 * a "[docN]" marker, and records the matching source metadata (title, URL,
 * type) in the citation store. The chat controller then forwards those
 * citations to the React frontend, which renders them as numbered references
 * and replaces each "[docN]" marker with a superscript link.
 */
#[FunctionCall(
  id: 'ys_contoso_chat:beacon_rag_search',
  function_name: 'ys_beacon_rag_search',
  name: 'Beacon RAG/Vector Search',
  description: 'This method will search one index for a search query and give back results with citation markers.',
  group: 'information_tools',
  context_definitions: [
    'index' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Index"),
      description: new TranslatableMarkup("The index to search in."),
      required: TRUE,
    ),
    'search_string' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Search String"),
      description: new TranslatableMarkup("The search string to search for."),
      required: TRUE,
    ),
    'amount' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup("Amount"),
      description: new TranslatableMarkup("The amount of results to find."),
      required: FALSE,
      default_value: 10,
    ),
    'min_score' => new ContextDefinition(
      data_type: 'float',
      label: new TranslatableMarkup("Minimal Score"),
      description: new TranslatableMarkup("The minimal score threshold to pass."),
      required: FALSE,
      default_value: 0.5,
    ),
  ],
)]
class BeaconRagTool extends RagTool {

  /**
   * The citation store.
   *
   * @var \Drupal\ys_contoso_chat\Service\CitationStore
   */
  protected CitationStore $citationStore;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
      $container->get('plugin.manager.ai_data_type_converter'),
    );
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->citationStore = $container->get('ys_contoso_chat.citation_store');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $this->index = $this->getContextValue('index');
    $this->searchString = $this->getContextValue('search_string');
    $amount = $this->getContextValue('amount');
    $min_score = $this->getContextValue('min_score');

    $end_results = [];
    $citations = [];

    /** @var \Drupal\search_api\Entity\Index $index */
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($this->index);
    if (!$index) {
      $this->setOutput("The index was not found.");
      return;
    }

    try {
      $query = $index->query(['limit' => $amount]);
      // Request chunk-level results so each match carries its source metadata.
      $query->setOption('search_api_ai_get_chunks_result', TRUE);
      $query->keys($this->searchString);
      $results = $query->execute();

      $i = 1;
      foreach ($results->getResultItems() as $result) {
        if ($min_score > $result->getScore()) {
          continue;
        }

        $content = (string) $result->getExtraData('content');
        $title = $result->getExtraData('title_1');
        $url = $result->getExtraData('url_1');

        // Label the context with a "[docN]" marker so the LLM can cite it.
        $label = "[doc{$i}]";
        if (is_string($title) && $title !== '') {
          $label .= " (source: {$title})";
        }
        $end_results[] = "Search result {$label}:\n```\n" . $content . "\n```\n\n";

        // Record the matching citation. Order must stay aligned with the
        // "[docN]" markers above so the frontend maps citations[N-1] correctly.
        $citations[] = [
          'content' => $content,
          'id' => (string) $i,
          'title' => is_string($title) ? $title : NULL,
          'filepath' => NULL,
          'url' => is_string($url) ? $url : NULL,
          'metadata' => $result->getExtraData('type'),
          'chunk_id' => $result->getExtraData('drupal_long_id'),
          'reindex_id' => NULL,
        ];
        $i++;
      }
    }
    catch (\Exception $e) {
      $this->setOutput("Failed to search the index");
      return;
    }

    if (count($end_results)) {
      $this->citationStore->setCitations($citations);
      $output = "Results from searching in the rag index " . $this->index . " for the following prompt: " . $this->searchString . ".\n";
      $output .= "Cite the sources you use by including their [docN] markers inline in your answer.\n";
      $output .= implode("\n", $end_results);
      $this->setOutput($output);
    }
    else {
      $this->setOutput("No results were found when searching in the rag index " . $this->index . " for the following prompt: " . $this->searchString . ".\n");
    }
  }

}
