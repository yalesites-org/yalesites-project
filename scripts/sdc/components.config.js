/**
 * Component specs for the SDC codegen (scripts/sdc/generate-sdc.js).
 *
 * One entry per component. Keys:
 *  - twig:   path (relative to component-library-twig) to the source template.
 *  - props:  path to the component's *-props.yml.
 *  - bundle: the Drupal block_content bundle machine name (for dial lookup).
 *  - dials:  map of Twig prop -> field_style_* machine name; enums are generated
 *            from ys_themes.component_overrides.yml + the field's view-display
 *            formatter (list_key -> keys, list_default -> labels).
 *  - group:  SDC group (Atoms/Molecules/Organisms).
 *  - libraryDependencies: existing atomic/* libraries to attach via libraryOverrides
 *            (for components with JS/CSS not in the global stylesheet).
 *
 * The three Wave 0 pilots are seeded here. Later waves append their components.
 */
module.exports = {
  divider: {
    twig: 'components/01-atoms/divider/yds-divider.twig',
    props: 'components/01-atoms/divider/divider-props.yml',
    bundle: 'divider',
    group: 'Atoms',
    dials: {
      divider__width: 'field_style_width',
      divider__position: 'field_style_position',
    },
  },

  callout: {
    twig: 'components/02-molecules/callout/yds-callout.twig',
    props: 'components/02-molecules/callout/callout-props.yml',
    bundle: 'callout',
    group: 'Molecules',
    dials: {
      callout__background_color: 'field_style_color',
      callout__alignment: 'field_style_alignment',
    },
  },

  accordion: {
    twig: 'components/02-molecules/accordion/yds-accordion.twig',
    props: 'components/02-molecules/accordion/accordion-props.yml',
    bundle: 'accordion',
    group: 'Molecules',
    libraryDependencies: ['atomic/accordion'],
    dials: {
      accordion__theme: 'field_style_color',
    },
  },
};
