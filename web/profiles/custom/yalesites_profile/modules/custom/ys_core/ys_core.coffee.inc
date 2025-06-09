<?php

/**
 * @file
 * Coffee commands for YaleSites.
 */

use Drupal\Core\Url;

/**
 * Implements hook_coffee_commands().
 */
function ys_core_coffee_commands() {
  $commands = [];

  $commands[] = [
    'value' => Url::fromRoute('view.manage_pages.page_1')->toString(),
    'label' => 'Manage Pages',
    'command' => ':manage_pages',
  ];

  $commands[] = [
    'value' => Url::fromRoute('view.manage_posts.page_1')->toString(),
    'label' => 'Manage Posts',
    'command' => ':manage_posts',
  ];

  $commands[] = [
    'value' => Url::fromRoute('view.manage_events.page_1')->toString(),
    'label' => 'Manage Events',
    'command' => ':manage_events',
  ];

  $commands[] = [
    'value' => Url::fromRoute('view.manage_profiles.page_1')->toString(),
    'label' => 'Manage Profiles',
    'command' => ':manage_profiles',
  ];

  // Add Media management commands.
  $commands[] = [
    'value' => Url::fromRoute('view.media_library.page')->toString(),
    'label' => 'Media Library',
    'command' => ':media_library',
  ];

  $commands[] = [
    'value' => Url::fromRoute('entity.media.collection')->toString(),
    'label' => 'Manage Media',
    'command' => ':manage_media',
  ];

  $commands[] = [
    'value' => Url::fromRoute('entity.media.add_page')->toString(),
    'label' => 'Add Media',
    'command' => ':add_media',
  ];

  // Add commands for creating different media types.
  $commands[] = [
    'value' => Url::fromRoute('entity.media.add_form', ['media_type' => 'document'])->toString(),
    'label' => 'Add Document',
    'command' => ':add_document',
  ];

  $commands[] = [
    'value' => Url::fromRoute('entity.media.add_form', ['media_type' => 'video'])->toString(),
    'label' => 'Add Video',
    'command' => ':add_video',
  ];

  $commands[] = [
    'value' => Url::fromRoute('entity.media.add_form', ['media_type' => 'image'])->toString(),
    'label' => 'Add Image',
    'command' => ':add_image',
  ];

  $commands[] = [
    'value' => Url::fromRoute('entity.media.add_form', ['media_type' => 'remote_video'])->toString(),
    'label' => 'Add Embed Video',
    'command' => ':add_embed_video',
  ];

  $commands[] = [
    'value' => Url::fromRoute('entity.media.add_form', ['media_type' => 'background_video'])->toString(),
    'label' => 'Add Background Video',
    'command' => ':add_background_video',
  ];

  // Add Taxonomy management command.
  $commands[] = [
    'value' => Url::fromRoute('entity.taxonomy_vocabulary.collection')->toString(),
    'label' => 'Manage Taxonomy',
    'command' => ':taxonomy',
  ];

  // Add Book management command.
  $commands[] = [
    'value' => Url::fromRoute('book.admin')->toString(),
    'label' => 'Manage Content Collections',
    'command' => ':content_collections',
  ];

  // Add Menu management commands.
  $commands[] = [
    'value' => Url::fromRoute('entity.menu.collection')->toString(),
    'label' => 'Manage Menus',
    'command' => ':menus',
  ];

  $commands[] = [
    'value' => Url::fromRoute('entity.menu.edit_form', ['menu' => 'main'])->toString(),
    'label' => 'Manage Main Menu',
    'command' => ':main_menu',
  ];

  $commands[] = [
    'value' => Url::fromRoute('entity.menu.edit_form', ['menu' => 'utility-drop-button-navigation'])->toString(),
    'label' => 'Manage Utility Drop-button Navigation Menu',
    'command' => ':utility_drop_nav',
  ];

  $commands[] = [
    'value' => Url::fromRoute('entity.menu.edit_form', ['menu' => 'utility-navigation'])->toString(),
    'label' => 'Manage Utility Navigation Menu',
    'command' => ':utility_nav',
  ];

  // Add Block management command.
  $commands[] = [
    'value' => Url::fromRoute('entity.block_content.collection')->toString(),
    'label' => 'Manage Reusable Blocks',
    'command' => ':blocks',
  ];

  // Add YaleSites settings commands.
  $commands[] = [
    'value' => Url::fromRoute('ys_core.admin_yalesites')->toString(),
    'label' => 'YaleSites Settings',
    'command' => ':yalesites',
  ];

  // Add YaleSites settings commands.
  $commands[] = [
    'value' => Url::fromRoute('ys_core.admin_site_settings')->toString(),
    'label' => 'Site Settings',
    'command' => ':site_settings',
  ];

  // Add YaleSites settings commands.
  $commands[] = [
    'value' => Url::fromRoute('ys_core.admin_header_settings')->toString(),
    'label' => 'Header Settings',
    'command' => ':header_settings',
  ];

  // Add YaleSites settings commands.
  $commands[] = [
    'value' => Url::fromRoute('ys_core.admin_footer_settings')->toString(),
    'label' => 'Footer Settings',
    'command' => ':footer_settings',
  ];

  // Add YaleSites settings commands.
  $commands[] = [
    'value' => Url::fromRoute('ys_core.admin_views_settings')->toString(),
    'label' => 'Views Settings',
    'command' => ':views_settings',
  ];

  // Add YaleSites settings commands.
  $commands[] = [
    'value' => Url::fromRoute('ys_integrations.admin_yalesites_integrations')->toString(),
    'label' => 'Integrations Settings',
    'command' => ':integrations',
  ];

  // Add URL management commands.
  $commands[] = [
    'value' => Url::fromRoute('redirect.list')->toString(),
    'label' => 'Manage Redirects',
    'command' => ':redirects',
  ];

  // Add user management commands.
  $commands[] = [
    'value' => Url::fromRoute('entity.user.collection')->toString(),
    'label' => 'Manage People',
    'command' => ':people',
  ];

  $commands[] = [
    'value' => Url::fromRoute('user.admin_create')->toString(),
    'label' => 'Add User',
    'command' => ':add_user',
  ];

  $commands[] = [
    'value' => Url::fromRoute('cas.bulk_add_cas_users')->toString(),
    'label' => 'Add CAS Users',
    'command' => ':add_cas_users',
  ];

  return $commands;
}
