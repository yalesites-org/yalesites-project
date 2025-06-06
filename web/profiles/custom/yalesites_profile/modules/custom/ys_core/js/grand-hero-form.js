/**
 * @file
 * JavaScript for the Grand Hero block form (final version).
 */
(function ($, Drupal, once) {
  'use strict';

  const GrandHeroForm = {
    config: {
      maxRetries: 10,
      retryDelay: 300
    },

    selectors: {
      replaceHeadingCheckbox: 'input[name*="[field_replace_heading_with_image]"]',
      overlayField: 'fieldset.js-media-library-widget[data-drupal-selector*="field-overlay-png"]'
    },

    initializedForms: {},
    activeRetries: {},

    getFormId: function ($context) {
      const $form = $context.is('form') ? $context : $context.closest('form');
      if (!$form.length) return 'unknown-form';
      return $form.attr('id') || $form.attr('name') || 'unknown-form';
    },

    cancelRetries: function (formId) {
      if (this.activeRetries[formId]) {
        clearTimeout(this.activeRetries[formId]);
        delete this.activeRetries[formId];
      }
    },

    updateFieldVisibility: function ($replaceHeadingCheckbox, $overlayField) {
      const isChecked = $replaceHeadingCheckbox.is(':checked');
      if (isChecked) {
        $overlayField.fadeIn(200);
      } else {
        $overlayField.fadeOut(200);
      }
    },

    init: function ($context) {
      try {
        const formId = this.getFormId($context);
        if (this.initializedForms[formId]) {
          return true;
        }

        const $replaceHeadingCheckbox = $context.find(this.selectors.replaceHeadingCheckbox);
        const $overlayField = $context.find(this.selectors.overlayField);

        if (!$replaceHeadingCheckbox.length || !$overlayField.length) {
          return false;
        }

        this.updateFieldVisibility($replaceHeadingCheckbox, $overlayField);

        $replaceHeadingCheckbox.on('change', () => {
          this.updateFieldVisibility($replaceHeadingCheckbox, $overlayField);
        });

        this.initializedForms[formId] = true;
        this.cancelRetries(formId);
        return true;
      } catch (error) {
        console.error('Grand Hero Form: Initialization error', error);
        return false;
      }
    },

    attemptInit: function ($context, retryCount = 0) {
      const formId = this.getFormId($context);
      if (this.initializedForms[formId]) {
        this.cancelRetries(formId);
        return true;
      }

      if (retryCount >= this.config.maxRetries) {
        return false;
      }

      const success = this.init($context);
      if (!success) {
        this.activeRetries[formId] = setTimeout(() => {
          this.attemptInit($context, retryCount + 1);
        }, this.config.retryDelay);
      }
      return success;
    }
  };

  Drupal.behaviors.grandHeroForm = {
    attach: function (context, settings) {
      once('grandHeroForm', 'form.layout-builder-add-block, form.layout-builder-update-block', context).forEach(function (form) {
        const $form = $(form);
        if ($form.find('#grand-hero-settings').length) {
          GrandHeroForm.attemptInit($form);
        }
      });
    }
  };

  $(document).on('dialog:aftercreate', function (event, dialog, $element, settings) {
    const $targetForm = $element.find('form.layout-builder-add-block, form.layout-builder-update-block').has('#grand-hero-settings');
    if ($targetForm.length) {
      GrandHeroForm.attemptInit($targetForm);
    }
  });

  $(document).on('drupalAjaxComplete', function (event, xhr, settings) {
    if (settings.selector && settings.selector.includes('layout-builder')) {
      const $newForm = $('form.layout-builder-add-block, form.layout-builder-update-block').has('#grand-hero-settings');
      if ($newForm.length) {
        GrandHeroForm.attemptInit($newForm);
      }
    }
  });

})(jQuery, Drupal, once);