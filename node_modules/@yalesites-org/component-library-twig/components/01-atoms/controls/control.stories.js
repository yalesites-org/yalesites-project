import ctaTwig from './cta/cta.twig';
import linkTwig from './text-link/text-link.twig';

/**
 * Storybook Definition.
 */
export default { title: 'Atoms/Controls' };

const ctaText = 'Call to action';

export const Cta = () => `
  <h2>Filled</h2>
  <div class="cta-group">
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
    })}
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__radius: 'soft',
    })}
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__radius: 'pill',
    })}
  </div>
  <h2>Outline</h2>
  <div class="cta-group">
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__style: 'outline',
    })}
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__radius: 'soft',
      cta__style: 'outline',
    })}
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__radius: 'pill',
      cta__style: 'outline',
    })}
  </div>
  <h2>Outline Weights</h2>
  <div class="cta-group">
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__style: 'outline',
      cta__outline_weight: '1',
    })}
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__style: 'outline',
      cta__outline_weight: '2',
    })}
    ${ctaTwig({
      cta__content: ctaText,
      cta__href: '#',
      cta__style: 'outline',
      cta__outline_weight: '4',
    })}
  </div>
  <h2>Hover Effects</h2>
  <div class="cta-group">
    ${ctaTwig({
      cta__content: 'Fade',
      cta__href: '#',
    })}
    ${ctaTwig({
      cta__content: 'Rise',
      cta__hover_style: 'rise',
      cta__href: '#',
    })}
    ${ctaTwig({
      cta__content: 'Wipe',
      cta__hover_style: 'wipe',
      cta__href: '#',
    })}
  </div>
  <div class="cta-group">
    ${ctaTwig({
      cta__content: 'Fade',
      cta__style: 'outline',
      cta__href: '#',
    })}
    ${ctaTwig({
      cta__content: 'Rise',
      cta__style: 'outline',
      cta__hover_style: 'rise',
      cta__href: '#',
    })}
    ${ctaTwig({
      cta__content: 'Wipe',
      cta__style: 'outline',
      cta__hover_style: 'wipe',
      cta__href: '#',
    })}
  </div>
`;

export const textLink = () => `
  ${linkTwig({
    link__url: '#',
    link__content: 'This is a default link',
    link__attributes: {
      target: '_blank',
    },
  })}<br />
  ${linkTwig({
    link__url: '#',
    link__content: 'This is a "no-underline" link',
    link__style: 'no-underline',
  })}
`;
