<?php

/**
 * @file
 * Redis configuration file.
 */

if (defined(
 'PANTHEON_ENVIRONMENT'
) && !\Drupal\Core\Installer\InstallerKernel::installationAttempted(
) && extension_loaded('redis')) {
 // Set Redis as the default backend for any cache bin not otherwise specified.
 $settings['cache']['default'] = 'cache.backend.redis';

 //phpredis is built into the Pantheon application container.
 $settings['redis.connection']['interface'] = 'PhpRedis';

 // These are dynamic variables handled by Pantheon.
 $settings['redis.connection']['host'] = $_ENV['CACHE_HOST'];
 $settings['redis.connection']['port'] = $_ENV['CACHE_PORT'];
 $settings['redis.connection']['password'] = $_ENV['CACHE_PASSWORD'];

 $settings['redis_compress_length'] = 100;
 $settings['redis_compress_level'] = 1;

 $settings['cache_prefix']['default'] = 'pantheon-redis';

 $settings['cache']['bins']['form'] = 'cache.backend.database'; // Use the database for forms

 // Apply changes to the container configuration to make better use of Redis.
 // This includes using Redis for the lock and flood control systems, as well
 // as the cache tag checksum. Alternatively, copy the contents of that file
 // to your project-specific services.yml file, modify as appropriate, and
 // remove this line.
 $settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';

 // Allow the services to work before the Redis module itself is enabled.
 $settings['container_yamls'][] = 'modules/contrib/redis/redis.services.yml';

 // Manually add the classloader path, this is required for the container
 // cache bin definition below.
 $class_loader->addPsr4('Drupal\\redis\\', 'modules/contrib/redis/src');

 /**
 * Default TTL for Redis is 1 year.
 *
 * Change cache expiration (TTL) for data,default bin - 12 hours.
 * Change cache expiration (TTL) for entity bin - 48 hours.
 *
 * @see \Drupal\redis\Cache\CacheBase::LIFETIME_PERM_DEFAULT
 */

 $settings['redis.settings']['perm_ttl'] = 2630000; // 30 days
 $settings['redis.settings']['perm_ttl_config'] = 43200;
 $settings['redis.settings']['perm_ttl_data'] = 43200;
 $settings['redis.settings']['perm_ttl_default'] = 43200;
 $settings['redis.settings']['perm_ttl_entity'] = 172800;

 // Use redis for container cache.
 // The container cache is used to load the container definition itself, and
 // thus any configuration stored in the container itself is not available
 // yet. These lines force the container cache to use Redis rather than the
 // default SQL cache.
 $settings['bootstrap_container_definition'] = [
   'parameters' => [],
   'services' => [
     'redis.factory' => [
       'class' => 'Drupal\redis\ClientFactory',
     ],
     'cache.backend.redis' => [
       'class' => 'Drupal\redis\Cache\CacheBackendFactory',
       'arguments' => [
         '@redis.factory',
         '@cache_tags_provider.container',
         '@serialization.phpserialize',
       ],
     ],
     'cache.container' => [
       'class' => '\Drupal\redis\Cache\PhpRedis',
       'factory' => ['@cache.backend.redis', 'get'],
       'arguments' => ['container'],
     ],
     'cache_tags_provider.container' => [
       'class' => 'Drupal\redis\Cache\RedisCacheTagsChecksum',
       'arguments' => ['@redis.factory'],
     ],
     'serialization.phpserialize' => [
       'class' => 'Drupal\Component\Serialization\PhpSerialize',
     ],
   ],
 ];
}
