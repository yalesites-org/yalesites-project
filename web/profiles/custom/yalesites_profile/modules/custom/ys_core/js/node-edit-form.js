(function drupal(Drupal) {
  Drupal.behaviors.ysCoreNodeEditForm = {
    attach: function attach(context, settings) {
      once("nodeEditForm", "html").forEach(function nodeEditForm() {
        const nodeTitle = context.getElementById("edit-title-0-value");
        const teaserTitle = context.getElementById(
          "edit-field-teaser-title-0-value"
        );

        function titleKeyUp() {
          teaserTitle.placeholder = nodeTitle.value;
        }

        // Set teaser placeholder initially on page load.
        teaserTitle.placeholder = nodeTitle.value;

        // Automatically set teaser placeholder on change of node title.
        ["blur", "keyup"].forEach((evt) =>
          nodeTitle.addEventListener(evt, titleKeyUp)
        );
      });
    },
  };
})(Drupal);
