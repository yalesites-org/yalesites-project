<?php

if ($_POST['user_email'] !== 'yalesites@yale.edu') {
  // Update databases.
  echo "Running drush deploy...\n";
  passthru('drush deploy');
  echo "Drush deploy complete.\n";
}
