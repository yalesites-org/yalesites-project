<?php

namespace Drupal\ys_node_access;

/**
 * Manager for YaleSites Node Access.
 */
class NodeAccessManager {

  const YS_NODE_ACCESS_REALM = 'ys_node_access';

  const YS_NODE_ACCESS_GRANT_ID_PUBLIC = 0;

  const YS_NODE_ACCESS_GRANT_ID_PRIVATE = 1;

  const YS_NODE_ACCESS_UNPUBLISHED_REALM = 'ys_node_access_unpublished';

  const YS_NODE_ACCESS_GRANT_ID_UNPUBLISHED_ANY = 0;

  const YS_NODE_ACCESS_UNPUBLISHED_OWNER_REALM = 'ys_node_access_unpublished_owner';

}
