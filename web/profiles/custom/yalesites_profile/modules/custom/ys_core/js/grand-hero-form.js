/**
 * @file
 * JavaScript for the Grand Hero block form.
 */
(function ($, Drupal, once) {
  'use strict';

  const GrandHeroForm = {
    config: {
      maxRetries: 10,
      retryDelay: 300,
      defaultDisplayMode: 'text',
      defaultHeadingText: 'Heading text goes here'
    },

    selectors: {
      displayMode: 'select[name*="[field_display_mode]"]',
      headingField: 'input[name*="[field_heading]"], textarea[name*="[field_heading]"]',
      overlayField: 'input[name*="[field_overlay_png]"], button[data-drupal-selector*="field-overlay-png"]',
      formWrapper: '.form-wrapper, .js-form-wrapper',
      formItem: '.form-item, .js-form-item',
      requiredClass: '.js-form-required, .form-required',
      textFormatWrapper: '.js-text-format-wrapper',
      requiredIndicator: '.form-required',
      errorClass: '.error, .has-error',
      errorMessage: '.error-message, .form-error-message'
    },

    initializedForms: {},
    activeRetries: {},

    getFormId: function($context) {
      const $form = $context.is('form') ? $context : $context.closest('form');
      if (!$form.length) return 'unknown-form';
      let formId = $form.attr('id') || $form.attr('name');
      if (!formId) {
        formId = $form.parentsUntil('body').map(function() {
          return this.tagName + (this.className ? '.' + this.className.trim().replace(/\s+/g, '.') : '');
        }).get().reverse().join('>') || 'unknown-form';
      }
      return formId;
    },

    cancelRetries: function(formId) {
      if (this.activeRetries[formId]) {
        clearTimeout(this.activeRetries[formId]);
        delete this.activeRetries[formId];
      }
    },

    init: function($context) {
      try {
        const formId = this.getFormId($context);
        if (this.initializedForms[formId]) {
          return true;
        }

        const $displayModeSelect = $context.find(this.selectors.displayMode);
        const $headingField = $context.find(this.selectors.headingField);
        const $overlayField = $context.find(this.selectors.overlayField);

        if (!$displayModeSelect.length || !$headingField.length || !$overlayField.length) {
          return false;
        }

        const $headingWrapper = $headingField.closest(this.selectors.formWrapper);
        const $overlayWrapper = $overlayField.closest(this.selectors.formWrapper);
        const $headingFormItem = $headingField.closest(this.selectors.formItem);
        const $overlayFormItem = $overlayField.closest(this.selectors.formItem);

        this.setupFormValidation($context, $displayModeSelect, $headingField, $headingFormItem, $overlayField, $overlayFormItem);
        this.updateFieldVisibility($displayModeSelect, $headingWrapper, $overlayWrapper, $headingField, $headingFormItem, $overlayField, $overlayFormItem);

        $displayModeSelect.on('change', () => {
          this.updateFieldVisibility($displayModeSelect, $headingWrapper, $overlayWrapper, $headingField, $headingFormItem, $overlayField, $overlayFormItem);
        });

        this.initializedForms[formId] = true;
        this.cancelRetries(formId);
        return true;
      } catch (error) {
        console.error('Grand Hero Form: Initialization error', error);
        return false;
      }
    },

    attemptInit: function($context, retryCount = 0) {
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
    },

    setupFormValidation: function($context, $displayModeSelect, $headingInput, $headingFormItem, $overlayInput, $overlayFormItem) {
      const $form = $context.closest('form');
      if (!$form.length) return;

      const $overrideField = $('<input>')
        .attr('type', 'hidden')
        .attr('name', 'settings[block_form][field_heading][0][value]')
        .attr('id', 'heading-override-field');

      $form.append($overrideField);

      $form.on('submit', () => {
        const selectedValue = $displayModeSelect.val();
        if (selectedValue === 'image') {
          $overrideField.val(this.config.defaultHeadingText);
          this.makeFieldNotRequired($headingInput, $headingFormItem, $headingInput.closest(this.selectors.formWrapper));
        } else {
          $overrideField.val($headingInput.val());
        }
      });
    },

    updateFieldVisibility: function($displayModeSelect, $headingWrapper, $overlayWrapper, $headingInput, $headingFormItem, $overlayInput, $overlayFormItem) {
      const selectedValue = $displayModeSelect.val();

      if (selectedValue === 'text') {
        $headingWrapper.show();
        $overlayWrapper.hide();
        this.makeFieldRequired($headingInput, $headingFormItem, $headingWrapper);
        this.makeFieldNotRequired($overlayInput, $overlayFormItem, $overlayWrapper);
      } else {
        $headingWrapper.hide();
        $overlayWrapper.show();
        this.makeFieldRequired($overlayInput, $overlayFormItem, $overlayWrapper);
        this.makeFieldNotRequired($headingInput, $headingFormItem, $headingWrapper);
      }
    },

    makeFieldRequired: function($input, $formItem, $wrapper) {
      $input.prop('required', true).attr('required', 'required').attr('aria-required', 'true');
      $formItem.addClass('js-form-required form-required');
      const $label = $formItem.find('label');
      if ($label.length && !$label.find('.form-required').length) {
        $label.append('<span class="form-required" title="This field is required." style="display:none;">*</span>');
      }
    },

    makeFieldNotRequired: function($input, $formItem, $wrapper) {
      $input.prop('required', false).removeAttr('required aria-required').removeClass('error');
      $formItem.removeClass('js-form-required form-required has-error');
      $formItem.find('.form-required').remove();
      $formItem.find('.error-message, .form-error-message').remove();
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

  $(document).on('dialog:aftercreate', function(event, dialog, $element, settings) {
    const $targetForm = $element.find('form.layout-builder-add-block, form.layout-builder-update-block').has('#grand-hero-settings');
    if ($targetForm.length) {
      GrandHeroForm.attemptInit($targetForm);
    }
  });

  $(document).on('drupalAjaxComplete', function(event, xhr, settings) {
    if (settings.selector && settings.selector.includes('layout-builder')) {
      const $newForm = $('form.layout-builder-add-block, form.layout-builder-update-block').has('#grand-hero-settings');
      if ($newForm.length) {
        GrandHeroForm.attemptInit($newForm);
      }
    }
  });

})(jQuery, Drupal, once);