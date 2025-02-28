import imageTwig from './image/yds-image.twig';
import iconsTwig from './icons/yds-icons.twig';
import faIconsTwig from './fa-icons/yds-fa-icons.twig';

import imageData from './image/image.yml';
import figureData from './image/figure.yml';
import faIconData from './fa-icons/fa-icons.yml';

import './image/cl-image.scss';
import './icons/cl-icons.scss';

const svgIcons = require.context('../../../images/icons', true, /\.svg$/);
const icons = [];
svgIcons.keys().forEach((key) => {
  const icon = key.split('./')[1].split('.')[0];
  icons.push(icon);
});

/**
 * Storybook Definition.
 */
export default { title: 'Atoms/Images' };

export const Images = () => `
  <h2>Aspect Ratios</h2>
  <p>These are the current aspect ratios that we use in components that require a programmatic crop.</p>
  <div class="cl-image-examples">
    <div class="cl-image-example">
    <h2>16:9</h2>
    ${imageTwig(imageData.responsive_images['16x9'])}
    </div>
    <div class="cl-image-example">
    <h2>3:2</h2>
    ${imageTwig(imageData.responsive_images['3x2'])}
    </div>
    <div class="cl-image-example">
    <h2>1:1</h2>
    ${imageTwig(imageData.responsive_images['1x1'])}
    </div>
    <div class="cl-image-example">
    <h2>1:1.6</h2>
    ${imageTwig(imageData.responsive_images['1x1.6'])}
    </div>
    <div class="cl-image-example">
      <h2>4:3</h2>
      ${imageTwig(imageData.responsive_images['4x3'])}
    </div>
  </div>
`;

export const Figures = () => imageTwig(figureData);

export const Icons = () => iconsTwig({ icons });

export const FAIcons = () => faIconsTwig(faIconData);
