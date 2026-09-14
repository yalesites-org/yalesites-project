#!/usr/bin/env node
/**
 * generate-sdc.js — SDC codegen for the YaleSites component-library migration (epic #1351).
 *
 * Seeds a first-draft `<name>.component.yml` (and a `<name>.twig` shim) in
 * `atomic/components/<name>/` from a component-library component's existing
 * `*-props.yml`, its Twig template, and the Drupal dial config. The output is a
 * STARTING POINT that a human refines (see docs/sdc/recipe-convert-a-component-to-sdc.md);
 * it deliberately encodes the hard-won rules from the Wave 0 pilot so authoring
 * ~50 more schemas is tractable instead of a manual slog.
 *
 * What it resolves automatically (the "real work, not a reformat"):
 *  - the props.yml `twigProp` indirection (Storybook key -> real Twig variable);
 *  - drops props whose twigProp is a CSS custom property (`--x`) or data attribute
 *    (`data-x`) — those are layout-section styling, not component props;
 *  - remaps Storybook control types to JSON-Schema types
 *    (select->string+enum, text/string->string, boolean->boolean, number->number);
 *  - detects SLOTS from `{% block X %}` in the Twig (excluding the generic
 *    `prefix_suffix` wrapper block);
 *  - generates dial enums from ys_themes.component_overrides.yml, resolving the
 *    field FORMATTER (list_key -> machine keys, list_default -> labels) from the
 *    block's view display, and cross-checks against props.yml options (warns on drift);
 *  - authors Canvas-forward schemas (title/description, examples for required props).
 *
 * What still needs a human eye (flagged with CODEGEN-TODO in the output):
 *  - props typed `boolean` in Storybook that are really a value at render time
 *    (e.g. an image URL string) — verify against the block template;
 *  - the shim's slot forwarding for anything beyond the standard pattern.
 *
 * STATUS — read before running with --write.
 *
 * This is the Wave 0 pilot and it has NOT kept pace with the migration.
 * components.config.js carries specs for FOUR components (divider, callout,
 * accordion, lists) while atomic/components/ holds 48 committed schemas, so 44
 * of them were authored by hand and this script knows nothing about them.
 *
 * For the four it does know, its output no longer matches what is committed --
 * those files have been refined by hand since, exactly as intended above. The
 * divergence is substantive, not cosmetic: accordion.component.yml carries a
 * `libraryOverrides: { dependencies: [atomic/accordion] }` block this script
 * does not emit, and that block is what attaches the component JS (include and
 * embed bypass the Render API's #attached). Re-running --write over it would
 * silently drop the library and the component's JS would stop loading.
 *
 * So: --write is for seeding a NEW component's first draft only. Never run
 * `--all --write`, and diff before writing over a file that already exists.
 * Bringing this back to parity (44 more specs, plus libraryOverrides and
 * hand-written description support) is a real piece of work, not a tidy-up.
 *
 * Usage:
 *   node scripts/sdc/generate-sdc.js <name> [--write] [--shim]
 *   node scripts/sdc/generate-sdc.js --all [--write] [--shim]
 * Without --write it prints to stdout (dry run). --shim also emits the .twig shim.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const yaml = require('js-yaml');

const ROOT = path.resolve(__dirname, '..', '..');
const CLT = path.join(ROOT, 'component-library-twig');
const ATOMIC_COMPONENTS = path.join(ROOT, 'atomic', 'components');
const SYNC = path.join(ROOT, 'web/profiles/custom/yalesites_profile/config/sync');
const OVERRIDES = path.join(SYNC, 'ys_themes.component_overrides.yml');

const SPECS = require('./components.config.js');

/** Read + parse a YAML file relative to the CLT repo (or absolute), memoized. */
const _yamlCache = new Map();
function loadYaml(p) {
  const abs = path.isAbsolute(p) ? p : path.join(CLT, p);
  if (!_yamlCache.has(abs)) _yamlCache.set(abs, yaml.load(fs.readFileSync(abs, 'utf8')));
  return _yamlCache.get(abs);
}

/** All `{% block NAME %}` names in a Twig file, minus the generic wrapper block. */
function twigBlocks(twigPath) {
  const src = fs.readFileSync(path.join(CLT, twigPath), 'utf8');
  const names = new Set();
  const re = /\{%-?\s*block\s+([a-zA-Z0-9_]+)\s*-?%\}/g;
  let m;
  while ((m = re.exec(src)) !== null) names.add(m[1]);
  names.delete('prefix_suffix');
  return names;
}

/** The dial enum for a component field, resolved against its view-display formatter. */
function dialEnum(bundle, field) {
  const overrides = loadYaml(OVERRIDES);
  const entry = overrides[bundle] && overrides[bundle][field];
  if (!entry || !entry.values) return null;
  const keys = Object.keys(entry.values).map(String);
  const labels = Object.values(entry.values).map(String);
  const formatter = viewDisplayFormatter(bundle, field);
  // list_key renders the machine key; list_default renders the human label.
  const values = formatter === 'list_default' ? labels : keys;
  const def = formatter === 'list_default'
    ? String(entry.values[entry.default])
    : String(entry.default);
  return { values, default: def, formatter };
}

/** The formatter type (list_key / list_default / ...) for a field on a bundle. */
function viewDisplayFormatter(bundle, field) {
  const p = path.join(SYNC, `core.entity_view_display.block_content.${bundle}.default.yml`);
  if (!fs.existsSync(p)) return null;
  const display = loadYaml(p);
  return display.content && display.content[field] && display.content[field].type;
}

/** Map a Storybook control type to a JSON-Schema property fragment. */
function schemaForProp(key, def, spec) {
  const twigProp = def.twigProp || key;
  const title = def.name || key;
  const frag = { title };
  if (def.description) frag.description = def.description.trim();

  const dialField = spec.dials && spec.dials[twigProp];
  if (dialField) {
    const dial = dialEnum(spec.bundle, dialField);
    if (dial) {
      frag.type = 'string';
      frag.enum = dial.values;
      frag.default = dial.default;
      // cross-check props.yml options against the dial-derived enum
      if (def.options) {
        const opts = def.options.map(String).sort().join(',');
        const derived = dial.values.slice().sort().join(',');
        if (opts !== derived) {
          frag['x-codegen-warning'] =
            `props.yml options [${def.options}] differ from dial-derived enum ` +
            `[${dial.values}] (formatter: ${dial.formatter}) — verify.`;
        }
      }
      return { name: twigProp, frag };
    }
  }

  switch (def.type) {
    case 'select':
      frag.type = 'string';
      if (def.options) frag.enum = def.options.map(String);
      if (def.default !== undefined) frag.default = String(def.default);
      break;
    case 'boolean':
      frag.type = 'boolean';
      frag['x-codegen-todo'] =
        'Storybook boolean — confirm the block template does not pass a value ' +
        '(e.g. a URL string); if it can be NULL use type: [string, "null"].';
      break;
    case 'number':
      frag.type = 'number';
      break;
    default:
      frag.type = 'string';
  }
  if (def.required) frag.examples = [def.default !== undefined ? String(def.default) : 'Example'];
  return { name: twigProp, frag };
}

/** Build the component.yml object for a component spec. */
function buildComponentYml(spec) {
  const props = loadYaml(spec.props);
  const blocks = twigBlocks(spec.twig);
  const out = {
    $schema: 'https://git.drupalcode.org/project/drupal/-/raw/HEAD/core/assets/schemas/v1/metadata.schema.json',
    name: spec.title || cap(spec.name),
    status: 'stable',
    group: spec.group || 'Components',
    description: spec.description ||
      `Thin SDC wrapper over ${spec.twig}. Real template + SCSS stay in component-library-twig.`,
  };
  const properties = {};
  const required = [];
  const slots = {};

  for (const [key, def] of Object.entries(props)) {
    const twigProp = def.twigProp || key;
    // Drop CSS-custom-property / data-attribute twigProps (not component props).
    if (twigProp.startsWith('--') || twigProp.startsWith('data-')) continue;
    // A twigProp that is a Twig block is a SLOT, not a prop.
    if (blocks.has(twigProp)) {
      const [nm, slot] = makeSlot(twigProp, {
        title: def.name || twigProp,
        required: !!def.required,
        baseDescription: def.description || `The ${twigProp} slot.`,
      });
      slots[nm] = slot;
      continue;
    }
    const { name, frag } = schemaForProp(key, def, spec);
    properties[name] = frag;
    if (def.required) required.push(name);
  }

  // Content slots: Twig blocks not represented by a props.yml entry (e.g. a
  // wrapper's callout__items block fed by a Drupal field). Emit them as slots too.
  const propTwigProps = new Set(
    Object.values(props).map((d) => d.twigProp).filter(Boolean),
  );
  for (const block of blocks) {
    if (propTwigProps.has(block)) continue; // already handled via props loop
    const [nm, slot] = makeSlot(block, {
      title: cap(block.replace(/_+/g, ' ').trim()),
      required: false,
      baseDescription: `The ${block} slot (Twig block, typically fed by a Drupal field).`,
    });
    if (slots[nm]) continue;
    slots[nm] = slot;
  }

  out.props = { type: 'object' };
  if (required.length) out.props.required = required;
  out.props.properties = properties;
  if (Object.keys(slots).length) out.slots = slots;
  if (spec.libraryDependencies) out.libraryOverrides = { dependencies: spec.libraryDependencies };
  return out;
}

/**
 * The SDC slot name for a Twig block. The shim injects into the CLT block of the
 * same name, so rename `*__items` blocks to `*__content` to avoid a collision
 * between the top-level receiver block and the CLT-injection block.
 */
function slotName(block) {
  return block.replace(/__items$/, '__content');
}

/** Build a [name, slotObject] pair, appending the collision note when renamed. */
function makeSlot(block, { title, required, baseDescription }) {
  const nm = slotName(block);
  const suffix = nm !== block
    ? ` Named ${nm} (not ${block}) to avoid a Twig block-name collision.`
    : '';
  return [nm, { title, required, description: baseDescription.trim() + suffix }];
}

function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

/** Emit YAML with a stable key order roughly matching the hand-written pilots. */
function dumpYaml(obj) {
  return yaml.dump(obj, { lineWidth: 100, noRefs: true, quotingType: '"', sortKeys: false });
}

function run() {
  const args = process.argv.slice(2);
  const write = args.includes('--write');
  const all = args.includes('--all');
  const names = all ? Object.keys(SPECS) : args.filter((a) => !a.startsWith('--'));
  if (!names.length) {
    console.error('Usage: node generate-sdc.js <name>|--all [--write]');
    process.exit(1);
  }
  // `--all --write` would rewrite every spec'd component at once, and the four
  // this script still knows have all been refined by hand since it produced
  // them (accordion's libraryOverrides block, which attaches its JS, is not
  // something this script emits). Seeding one new component is the supported
  // use; bulk-overwriting existing ones is not. See the STATUS note on top.
  if (all && write) {
    console.error(
      '--all --write is refused: it would overwrite hand-refined schemas.\n' +
      'Run it per component, and diff before writing over an existing file.'
    );
    process.exit(1);
  }
  for (const name of names) {
    const spec = SPECS[name];
    if (!spec) { console.error(`No spec for "${name}" in components.config.js`); continue; }
    const obj = buildComponentYml({ name, ...spec });
    const ymlText = dumpYaml(obj);
    if (write) {
      const dir = path.join(ATOMIC_COMPONENTS, name);
      const target = path.join(dir, `${name}.component.yml`);
      // Refuse to clobber silently. The existing file is very likely the
      // hand-refined version; --force is the deliberate opt-in.
      if (fs.existsSync(target) && !args.includes('--force')) {
        console.error(
          `${path.relative(ROOT, target)} already exists; refusing to overwrite.\n` +
          'Diff it against a dry run first, then pass --force if you really mean it.'
        );
        continue;
      }
      fs.mkdirSync(dir, { recursive: true });
      fs.writeFileSync(target, ymlText);
      console.log(`wrote ${path.relative(ROOT, target)}`);
    } else {
      console.log(`# ===== ${name}.component.yml (dry run) =====`);
      console.log(ymlText);
    }
  }
}

if (require.main === module) run();
module.exports = { buildComponentYml, dialEnum, twigBlocks, viewDisplayFormatter };
