namespace Drupal\ys_layouts\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\layout_builder\SectionComponent;
use Drush\Commands\DrushCommands;

/**
 * Updates profile meta blocks.
 */
class UpdateProfileMetaBlock extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new UpdateProfileCommand object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Updates empty profile meta blocks with new default configuration.
   *
   * @command ys_layouts:update-profile-meta-block
   *
   * @usage drush ys_layouts:update-profile-meta-block
   */
  public function updateProfileMetaBlocks(): void {
    $beforeDate = strtotime('2024-06-24');
    $config = [
      'image_orientation' => 'landscape',
      'image_style' => 'indent',
      'image_alignment' => 'right',
    ];

    $nids = $this->getProfilesBeforeDate($beforeDate);
    foreach ($nids as $nid) {
      /** @var \Drupal\node\Node $node */
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      $layout = $node->get('layout_builder__layout')->getValue();

      if (!empty($layout)) {
        foreach ($layout as $section) {
          if (isset($section['section']) && $section['section']->getLayoutId() == 'ys_layout_banner') {
            $components = $section['section']->getComponents();
            foreach ($components as $component) {
              if ($component instanceof SectionComponent && $component->getPluginId() === 'profile_meta_block') {
                $configuration = $component->get('configuration');
                foreach ($config as $key => $value) {
                  // Set only if it's not set.
                  if (!array_key_exists($key, $configuration)) {
                    $configuration[$key] = $value;
                  }
                }
                $component->setConfiguration($configuration);
              }
            }
          }
        }
      }

      $node->set('layout_builder__layout', $layout);
      try {
        $node->save();
        print("Updated profile meta block for node $nid\n");
      }
      catch (\Exception $ex) {
        print("Failed to update profile meta block for node $nid\n");
      }
    }

  }

  /**
   * Get all profiles created before a given date.
   *
   * @param int $date
   *   The date to compare.
   *
   * @return array
   *   An array of node ids.
   */
  private function getProfilesBeforeDate($date): array {
    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('created', $date, '<')
      ->condition('type', 'profile')
      ->accessCheck(FALSE);
    return $query->execute();
  }

}

$drushCmd = new UpdateProfileMetaBlock(\Drupal::entityTypeManager());
$drushCmd->updateProfileMetaBlocks();
