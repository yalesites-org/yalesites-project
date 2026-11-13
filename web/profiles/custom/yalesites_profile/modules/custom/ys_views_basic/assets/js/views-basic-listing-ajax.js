/**
 * @file
 * Keeps a listing's AJAX filter submit from re-sending the page's query.
 *
 * Core's Drupal.views.ajaxView builds its AJAX URL from the views AJAX path
 * plus window.location.search, and the exposed form submit appends the form's
 * own fields to that. Single-value fields are overridden by the form, but
 * multi-value filters (the taxonomy and year filters) accumulate, and an
 * empty multi-select sends nothing at all. So once the URL carries filter
 * values for one listing, filtering another listing with JS also applied the
 * first listing's terms to it, and a listing's own terms could never be
 * deselected (YaleSites-Internal#1926).
 *
 * When the form names its listing and the URL holds listing-scoped values,
 * use the bare AJAX path: the form re-sends every field it needs. URLs with
 * no listing argument (links made before #1926) keep core's behavior.
 *
 * The constructor builds the pager and RefreshView AJAX from the same
 * element_settings after this runs, so they get the bare path too. That is
 * fine for pager links: their submit data is parsed from the link href,
 * which carries the full query, listing included.
 */
((Drupal, drupalSettings) => {
  if (!Drupal.views || !Drupal.views.ajaxView) {
    return;
  }

  const { attachExposedFormAjax } = Drupal.views.ajaxView.prototype;

  Drupal.views.ajaxView.prototype.attachExposedFormAjax =
    function listingAttachExposedFormAjax(...args) {
      if (
        this.$exposed_form.find('input[name="listing"]').length &&
        new URLSearchParams(window.location.search).has('listing')
      ) {
        // Resolved exactly as core's ajaxView constructor does.
        let ajaxPath = drupalSettings.views.ajax_path;
        if (Array.isArray(ajaxPath)) {
          [ajaxPath] = ajaxPath;
        }
        this.element_settings.url = ajaxPath;
      }
      return attachExposedFormAjax.apply(this, args);
    };
})(Drupal, drupalSettings);
