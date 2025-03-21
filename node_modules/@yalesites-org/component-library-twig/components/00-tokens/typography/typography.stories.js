import tokens from '@yalesites-org/tokens/build/json/tokens.json';

// Twig templates
import typeFaces from './type-faces.twig';
import typeScale from './type-scale.twig';
import headingStyles from './heading-styles.twig';
import bodyStyles from './body-styles.twig';

// Data files
import typeFacesData from './type-faces.yml';

const scaleData = { font_scale: tokens.font.scale };
const headingStyleData = { heading_styles: tokens.font.style.heading };
const bodyStyleData = { body_styles: tokens.font.style.body };
const letterSpacing = { letter_spacing: tokens.font.letterSpacing };
const textTransforms = { text_transforms: tokens.font.textTransform };

/**
 * Storybook Definition.
 */
export default { title: 'Tokens/Typography' };

export const TypeFaces = () => typeFaces(typeFacesData);

export const TypeScale = () => typeScale(scaleData);

export const HeadingStyles = () =>
  headingStyles({ ...headingStyleData, ...letterSpacing, ...textTransforms });

export const BodyStyles = () => bodyStyles(bodyStyleData);
