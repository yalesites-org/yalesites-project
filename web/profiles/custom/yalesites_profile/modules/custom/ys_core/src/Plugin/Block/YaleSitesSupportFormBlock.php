<?php

namespace Drupal\ys_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Session\AccountInterface;

/**
 * Adds a support form link block.
 *
 * @Block(
 *   id = "support_form_block",
 *   admin_label = @Translation("Support Form Block"),
 *   category = @Translation("YaleSites Core"),
 * )
 */
class YaleSitesSupportFormBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Drupal site settings.
   *
   * @var Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $siteSettings;

  /**
   * Symfony request - to get base URL.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Account interface.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('request_stack'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, RequestStack $request_stack, AccountInterface $account) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->siteSettings = $config_factory->get('system.site');
    $this->request = $request_stack->getCurrentRequest();
    $this->account = $account;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    $siteURL = $this->request->getSchemeAndHttpHost();
    $siteName = $this->siteSettings->get('name');
    $currentUserName = $this->account->getAccountName();
    $currentUserRoles = implode(", ", $this->account->getRoles());
    return [
      '#theme' => 'ys_support_form',
      '#link' => "https://yale.service-now.com/it?id=incident_form&prefill_u_business_service=e9688dcd6fbb31007ee2abcf9f3ee40c&prefill_u_category=b74b15c16ffb31007ee2abcf9f3ee4b3&prefill_short_description=Yalesites%20Service%20Request:%20{$siteURL}&prefill_description=Please%20enter%20your%20support%20request%20here:%0d%0d%0d%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%0dDiagnostic%20info%20(do%20not%20edit%20this%20section)%0dSite Name:%20{$siteName}%0dUser Name:%20{$currentUserName}%0dUser Roles:%20{$currentUserRoles}%0dUser-agent:%20{$userAgent}%0d%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D%3D",
    ];
  }

}
