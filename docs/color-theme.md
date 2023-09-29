# Working with color and themes

## Jump to:
- [Adding a new color value](#adding-a-new-color-value)
- [Adding a new component theme](#adding-a-new-component-theme)
- [Adding a new global theme](#adding-a-new-global-theme)
- [Working with themes in CSS](#working-with-themes-in-css)

---

## Adding a new color value
To add a new color value to the design system: 
- **Step 1**: In the `tokens` repository ([github](https://github.com/yalesites-org/tokens)) 
  - open the `tokens/figma-export/tokens.json` file
  - Locate the `color` key which should look something like the following:
```
  "color": {
    "blue": {
      "yale": {
        "value": "#00356b",
        "type": "color"
      },
      "athens": {
        "value": "#3E75AD",
        "type": "color"
      },
      "whipple": {
        "value": "#779FB1",
        "type": "color"
      },
    },
    "basic": {
      "white": {
        "value": "#ffffff",
        "type": "color"
      },
      "black": {
        "value": "#000000",
        "type": "color"
      },
      "brown-gray": {
        "value": "#787069",
        "type": "color"
      }
    },
```
---
- **Step 2**: Add your new color value
  - for example, say you want to add the color brown (`#725811`)
  - After the `blue` or `basic` entry referenced above, you could add your entry for `brown`
```
    "brown": {
      "yale": {
        "value": "#725811",
        "type": "color"
      }
    }
```
---
- **Step 3**: Compile your changes
  - Following the [readme instructions in the Tokens repository](https://github.com/yalesites-org/tokens#developing-on-the-tokens-within-the-component-library)
  - In your terminal window, navigate to your tokens repository. This is mostly likely here: `yalesites-project/web/themes/contrib/atomic/_yale-packages/tokens`. If you haven't created a new branch in the tokens repository, create one now. e.g. `yalb-1234-add-brown-color`.
  - When you're ready to compile changes, run `npm run build`. Your changes should be compiled.
  ---
  
- **Step 4**: Commit your changes and open a pull request
  - Commit your changes with a message which follows our commit message convention: `feat:(yalb-1234) add brown yale value`
  - Push the new branch up to the tokens repository and open a new Pull Request
  - Once your change has been reviewed and approved, it can be merged in and a new tokens release will be created using github actions.

---
- **Step 5**: Using your new color token - updating to the latest tokens release
  - Once there is a new tokens release, the [component library](https://github.com/yalesites-org/component-library-twig) will need to update to the latest tokens release (`npm update @yalesites-org/tokens`), and then you can use the new color.
  - In the component library, after you've run the `npm update @yalesites-org/tokens` command, you can commit this change to the `package-lock.json` file. When you push the change to github and the pull request goes through the build process, the new color should be visible on the `colors` page: `/?path=/story/tokens-colors--colors`.
  - **Note:** you may need to login to [Netlify](https://app.netlify.com/) to clear the branch preview's cache. Token changes tend to get cached on Netlify.
  - Next, you may want to incorporate your new color value into a new global theme. 

---

### Adding a new global theme 
Global themes are visualized here: https://yalesites-org.github.io/component-library-twig/?path=/story/tokens-colors--global-theme-color-pairings

- **Step 1**: In the `tokens` repository ([github](https://github.com/yalesites-org/tokens)) 
  - Open the `tokens/tokens/base/color.yml` file
  - Locate the `global-themes` key which should look something like the following:
  ```
global-themes:
  "one":
    label:
      value: "Old Blues"
    colors:
      slot-one:
        value: "{color.blue.yale.value}"
      slot-two:
        value: "{color.blue.athens.value}"
      slot-three:
        value: "{color.blue.whipple.value}"
      slot-four:
        value: "{color.blue.yarmouth.value}"
      slot-five:
        value: "{color.gray.hale.value}"
      slot-six:
        value: "{color.blue.yale.value}"
      slot-seven:
        value: "{color.gray.800.value}"
      slot-eight:
        value: "{color.basic.white.value}"
  ```
  - Add your new theme, following the same convention shown above. If there are `three` themes already entered, and yours would be number `four`, name it accordingly.
  - For example (say we wanted to use the new `brown` value we added above): 

```
  "four":
    label:
      value: "New Browntownswick"
    colors:
      slot-one:
        value: "{color.blue.yale.value}"
      slot-two:
        value: "{color.blue.athens.value}"
      slot-three:
        value: "{color.brown.yale.value}"
      slot-four:
        value: "{color.green.overcast.value}"
      slot-five:
        value: "{color.blue.yorktown.value}"
      slot-six:
        value: "{color.blue.yale.value}"
      slot-seven:
        value: "{color.gray.800.value}"
      slot-eight:
        value: "{color.basic.white.value}"
```

- **Step 2**: Compile your changes
  - Following the [readme instructions in the Tokens repository](https://github.com/yalesites-org/tokens#developing-on-the-tokens-within-the-component-library)
  - In your terminal window, navigate to your tokens repository. This is mostly likely here: `yalesites-project/web/themes/contrib/atomic/_yale-packages/tokens`. If you haven't created a new branch do so now. e.g. `yalb-add-global-theme-four`.
  - When you're ready to compile changes, run `npm run build`. Your changes should be compiled.
  ---
  
- **Step 3**: Commit your changes and open a pull request
  - Commit your changes with a message which follows our commit message convention `feat:(yalb-1235) add global theme six`
  - Push the new branch up to the tokens repository and open a new Pull Request
  - Once your change is reviewed it will be merged in and a new tokens release will be created using github actions.

---
- **Step 4**: Using your new global theme
  - Once there is a new tokens release, the [component library](https://github.com/yalesites-org/component-library-twig) will need to be updated to use the latest tokens release (`npm update @yalesites-org/tokens`), and then you can use the new color.
  - Your new global theme should be ready to use.

---
- **Step 5**: 
  - Unless you are adding a brand new `slot-NUMBER` value (e.g. `slot-four`) to the new global theme, the new theme will be included in every component context which iterates over the global theme token values.
  - If you have added a new `slot-NUMBER` then you'll need to add a new variable and map it accodingly to each component in which it is applicable.

---
**Step 6**:
  - Review components that may use the new global theme and make sure colors have been mapped appropriately. 

---

### Adding a new component theme
Adding a new component theme is similar to adding a new global theme. In addition to component themes, we also have separate files for specific component themes. Component themes live alongside global themes because they are used by more than one component.

Component themes are visualized here: https://yalesites-org.github.io/component-library-twig/?path=/story/tokens-colors--component-theme-color-pairings

**Component themes are used by the following components:**
- Action Banner (`component-library-twig/components/02-molecules/banner/action/yds-action-banner.twig`)
- Button CTA (`component-library-twig/components/01-atoms/controls/cta/yds-cta.twig`)
- Grand Hero Banner (`component-library-twig/components/02-molecules/banner/grand-hero/yds-grand-hero.twig`)
- Callouts (`component-library-twig/components/02-molecules/callout/yds-callout.twig`)
- Pull Quotes (`component-library-twig/components/02-molecules/pull-quote/yds-pull-quote.twig`)
- Quick Links (`component-library-twig/components/02-molecules/quick-links/yds-quick-links.twig`)
- Tabs (`component-library-twig/components/02-molecules/tabs/yds-tabs.twig`)

The purpose in registering these component themes is to establish default values from which a global theme will be iterated over to apply color slots, changing color values depending on the active global theme.

**Note:** 
There are specific component theme files for the following components:

**atoms**
- `tokens/atoms/links.yml` - are used to define default link color value variables and those variables are overridden by global or component themes.  

**molecules**
- `tokens/molecules/alert.yml` - uses component-specific `alert` themes

**organisms**
- `tokens/organisms/primary-nav.yml` - these themes are only applicable to typography, not color themes
- `tokens/organisms/site-footer.yml` - uses component-specific `site-footer` themes which leverage `global-themes`
- `tokens/organisms/site-header.yml` - uses component-specific `site-header` themes which leverage `global-themes`

The process of updating these, more specific, component themes is the same as what follows, below. The `site-footer` and `site-header` have 5 themes, which requires them to be organized separately from `component-themes`, which only have 3 themes defined. Both `site-footer` and `site-header` make use of the global theme color palettes in the same way in which our `component-themes` do.

- **Step 1**: In the `tokens` repository ([github](https://github.com/yalesites-org/tokens)) 
  - Open the `tokens/tokens/base/color.yml` file
  - Locate the `component-themes` key which should look something like the following:
  ```
  component-themes:
  "one":
    background:
      value: "{color.blue.yale.value}"
    text:
      value: "{color.basic.white.value}"
    heading:
      value: "{color.basic.white.value}"
    slot-one:
      value: "{color.blue.yale.value}"
    slot-two:
      value: "{color.blue.athens.value}"
    slot-three:
      value: "{color.blue.whipple.value}"
    slot-four:
      value: "{color.blue.yarmouth.value}"
    slot-five:
      value: "{color.gray.hale.value}"
    slot-six:
      value: "{color.blue.yale.value}"
    slot-seven:
      value: "{color.gray.800.value}"
    slot-eight:
      value: "{color.basic.white.value}"
  ```
  - Add your new theme following the same convention outlined here. If there are `three` themes already entered, and yours would be number `four`, name it accordingly.
  - For example (say we wanted to use the new `brown` value we added above): 

```
  "four":
    background:
      value: "{color.blue.yale.value}"
    text:
      value: "{color.basic.white.value}"
    heading:
      value: "{color.basic.white.value}"
    slot-one:
      value: "{color.blue.yale.value}"
    slot-two:
      value: "{color.blue.athens.value}"
    slot-three:
      value: "{color.brown.yale.value}"
    slot-four:
      value: "{color.green.overcast.value}"
    slot-five:
      value: "{color.blue.yorktown.value}"
    slot-six:
      value: "{color.blue.yale.value}"
    slot-seven:
      value: "{color.gray.800.value}"
    slot-eight:
      value: "{color.basic.white.value}"
```

- **Step 2**: Compile your changes
  - Follow the [readme instructions in the Tokens repository](https://github.com/yalesites-org/tokens#developing-on-the-tokens-within-the-component-library)
  - In your terminal window, navigate to your tokens repository. This is mostly likely here: `yalesites-project/web/themes/contrib/atomic/_yale-packages/tokens`. If you haven't created a new branch do so now. e.g. `yalb-add-component-theme-four` or `yalb-ticketNUMXXX`.
  - When you're ready to compile changes, run `npm run build`. Your changes should be compiled and you should see new files output to the `/build` directory.
  ---
  
- **Step 3**: Commit your changes and open a pull request
  - Commit your changes with a message which follows our commit message convention `feat:(yalb-1236) add component theme four`
  - Push the new branch up to the tokens repository and open a new Pull Request
  - Once your change is reviewed it will be merged in and a new tokens release will be created using github actions.

---
- **Step 4**: Using your new component theme
  - Once there is a new tokens release, the [component library](https://github.com/yalesites-org/component-library-twig) will need to update to the latest tokens release (`npm update @yalesites-org/tokens`), and then you can use the new color.
  - Your new global theme should be ready to use. 
----

- **Step 5**: Mapping component variables to global theme variables (`slots`)
  - With your new component theme in place, you can assign your component theme variables. 
  - For example, if you were to add a new component theme called `four` and add it to the `atomic/_yale-packages/component-library-twig/components/02-molecules/callout/_yds-callout.scss` file, it might look something like this: 
  ```
  &[data-component-theme='four'] {
    --color-callouts-background: var(--color-slot-five); // what global color slot should the background color map to?
    --color-text-shadow: var(--color-slot-one); // this should be set to the same slot as --color-cta-secondary
    --color-action: var(--color-basic-white); // if the background is dark, this is likely `white`
    --color-cta-secondary: var(--color-slot-one);
    --color-text: var(--color-basic-white); // if the background is dark, this is likely `white`
    --color-heading: var(--color-basic-white); // if the background is dark, this is likely `white`
  }
  ```
  - Because not every component makes use of a global-theme, and each component theme applies global-theme colors in different ways, this manual step is required to map component-specific variables to a `--color-slot` variable. 

  ---

### Working with themes in CSS
In each component that uses component themes and global themes, each theme should be iterated over so that each component can override its default values set in tokens. Rather, each component can get a new color-slot mapping from each global-theme. 

#### Part one
For example, in our `_yds-callout.scss` file (https://github.com/yalesites-org/component-library-twig/blob/develop/components/02-molecules/callout/_yds-callout.scss), at the top we include our tokens and the map function. Then we set variables to out `component` and `global` theme objects.

```
@use '~@yalesites-org/tokens/build/scss/tokens' as sass-tokens;
@use '../../00-tokens/tokens';
@use '../../00-tokens/functions/map';
@use '../../01-atoms/atoms';

$component-callout-themes: map.deep-get(tokens.$tokens, 'component-themes');
$global-callout-themes: map.deep-get(tokens.$tokens, 'global-themes');
```

This sets us up to generate theme-based styles as programmatically as possible.

#### Part two
For the `.callouts` selector, we define default values based on each component themes. These defaults match component-theme option `one`. In case our themes don't use a global theme, we need color assignments so they render properly. These values directly map to their token-based values, as set in each component-theme.

We can also set any component-specific token-variables (such as `--color-callouts-background` and `--color-text-shadow`), aside from mapping color-slots: 

```
  // Component themes defaults: iterate over each component theme to establish
  // default variables.
  @each $theme, $value in $component-callout-themes {
    &[data-component-theme='#{$theme}'] {
      --color-callouts-background: var(--component-themes-#{$theme}-background);
      --color-text-shadow: var(--component-themes-#{$theme}-background);
      --color-slot-one: var(--component-themes-#{$theme}-slot-one);
      --color-slot-two: var(--component-themes-#{$theme}-slot-two);
      --color-slot-three: var(--component-themes-#{$theme}-slot-three);
      --color-slot-four: var(--component-themes-#{$theme}-slot-four);
      --color-slot-five: var(--component-themes-#{$theme}-slot-five);
      --color-slot-six: var(--component-themes-#{$theme}-slot-six);
      --color-slot-seven: var(--component-themes-#{$theme}-slot-seven);
      --color-slot-eight: var(--component-themes-#{$theme}-slot-eight);
    }
  }
```

#### Part three
Next, we set the component-theme slot values, based on the global theme: 

```
  // Global themes: set color slots for each theme
  // This establishes `--color-slot-` variables name-spaced to the selector
  // in which it is used. We can map component-level variables to global-level
  // `--color-slot-` variables.
  @each $globalTheme, $value in $global-callout-themes {
    [data-global-theme='#{$globalTheme}'] & {
      --color-slot-one: var(--global-themes-#{$globalTheme}-colors-slot-one);
      --color-slot-two: var(--global-themes-#{$globalTheme}-colors-slot-two);
      --color-slot-three: var(--global-themes-#{$globalTheme}-colors-slot-three);
      --color-slot-four: var(--global-themes-#{$globalTheme}-colors-slot-four);
      --color-slot-five: var(--global-themes-#{$globalTheme}-colors-slot-five);
      --color-slot-six: var(--component-themes-#{$theme}-slot-six);
      --color-slot-seven: var(--component-themes-#{$theme}-slot-seven);
      --color-slot-eight: var(--component-themes-#{$theme}-slot-eight);
    }
  }
```

---

#### Part four
Next, we assign component theme attributes to specific global-theme color-slot values: 

```
  // Component theme overrides: set specific component theme overrides
  /// define component name spaced variables and map them to global theme slots.
  &[data-component-theme='one'] {
    --color-callouts-background: var(--color-slot-one);
    --color-text-shadow: var(--color-slot-one);
    --color-action: var(--color-basic-white);
    --color-action-secondary: var(--color-slot-one);
    --color-text: var(--color-basic-white);
    --color-heading: var(--color-basic-white);
  }

  &[data-component-theme='two'] {
    --color-callouts-background: var(--color-slot-four);
    --color-text-shadow: var(--color-slot-one);
    --color-action: var(--color-gray-800);
    --color-action-secondary: var(--color-basic-white);
    --color-text: var(--color-gray-800);
    --color-heading: var(--color-gray-800);
  }

  &[data-component-theme='three'] {
    --color-callouts-background: var(--color-slot-five);
    --color-text-shadow: var(--color-slot-one);
    --color-action: var(--color-basic-white);
    --color-action-secondary: var(--color-slot-one);
    --color-text: var(--color-basic-white);
    --color-heading: var(--color-basic-white);
  }
```

Because every component theme assigns global theme color slots differently, some level of manual assignment is necessary. CSS variables should be created for any new component as name-spaced variables based on the component name. `--color-action` and `--color-action-secondary` come from the `component-library-twig/components/01-atoms/controls/cta/_yds-cta.scss` file. We re-assign them here.

---
#### Part five
Commit your changes and open a PR.

---
### Other color use information
#### Components:
- **Banners**:`component-library-twig/components/02-molecules/banner/action/_yds-action-banner.scss` and `components/02-molecules/banner/grand-hero/_yds-grand-hero.scss` highlight great examples of re-mapping our `--color-link-base` and `--color-link-hover` variables to out theme color slots.
  - uses color slots `one`, `four`, and `five`.
- **Callouts**:`component-library-twig/components/02-molecules/callout/_yds-callout.scss`. 
  - Uses color slots `one`, `four`, and `five`.
- **CTA**: `component-library-twig/components/01-atoms/controls/cta/_yds-cta.scss` uses its own themes `data-cta-theme` themes. For instances in which a CTA is pulled in to other components, the existing component-specific CSS variables are used. This way we can re-map these variables within the component which uses it.
  - Uses color slots `one`, `two` , `three`, `four`, `five`, `six`, `seven`.
- **Quote**: `component-library-twig/components/02-molecules/pull-quote/_yds-pull-quote.scss` uses global themes for `--color-pull-quote-accent` only.
  - Uses color slots `one`, `three`, and `five`.
- **Site Header**: `component-library-twig/components/03-organisms/site-header/_yds-site-header.scss`
  - Uses color slots `one`, `two`, and `three`.
  - Header Accents use slots `one`, `two` , `three`, `four`, `five`, `six`, `seven`. 
- **Site Footer**: `component-library-twig/components/03-organisms/site-footer/_yds-site-footer.scss`
  - Uses color slots `one`, `three`, and `five`.
  - Footer Accents use slots `one`, `two` , `three`, `four`, `five`, `six`, `seven`. 
- **Tabs**: 
  - Uses color slots `one`, `two`, and `five`.
