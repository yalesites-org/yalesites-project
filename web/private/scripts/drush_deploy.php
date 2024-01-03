<?php
/*
* This script conditionally runs `drush deploy`.
* We only want it to run conditionally if:
*   1. The current workflow is a code sync
*   2. The previous workflow applied upstream updates
*   3. The user applying upstream updates is not yalesites@yale.edu
* This stops it from running from Quicksilver when we are updating sites in CI,
* but lets it run when manually applied from a site dashboard.
* This allows for the command output to be captured in CI.
*/

$workflowType = $_POST['wf_type'];
$userEmail = $_POST['user_email'];
$skipMessage = "Skipping drush deploy...\n";
$runMessage = "Running drush deploy...\n";

// Handle code_sync workflows.
if (in_array($workflowType, ['sync_code', 'sync_code_with_build'])) {
  $workflows = json_decode(pantheon_curl('https://api.live.getpantheon.com/sites/self/workflows?limit=5', NULL, 8443, 'GET')['body']);

  // sync_code is always run by the Pantheon user, so we need to check
  // if apply_upstream_updates was a nearby previous workflow triggered by a different user.
  // Get only the first occurence in case there are multiple.
  $previousWorkflow = null;
  foreach ($workflows as $workflow) {
    if ($workflow->type == 'apply_upstream_updates') {
      $previousWorkflow = $workflow;
      break;
    }
  }

  if ($previousWorkflow && $previousWorkflow->user_email !== 'yalesites@yale.edu') {
    print $runMessage;
    passthru('drush deploy');
  }
  else {
    print $skipMessage;
  }
}
// Handle deploy workflows.
elseif ($workflowType == 'deploy' && $userEmail !== 'yalesites@yale.edu') {
  print $runMessage;
  passthru('drush deploy');
}
// Handle any other workflows where it should always run.
else {
  print $runMessage;
  passthru('drush deploy');
}
