import link from './link.twig';

/**
 * Storybook Definition.
 */
export default { title: 'Atoms/Links' };

export const links = () => `
  ${link({
    link__url: '#',
    link__content: 'This is a default link',
    link__attributes: {
      target: '_blank',
    },
  })}<br />
  ${link({
    link__url: '#',
    link__content: 'This is a "no-underline" link',
    link__style: 'no-underline',
  })}
`;
