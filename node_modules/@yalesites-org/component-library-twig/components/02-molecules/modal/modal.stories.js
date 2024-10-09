import modalTwig from './yds-modal.twig';
import modalData from './modal.yml';
import './yds-modal';

export default {
  title: 'Molecules/Modal',
};

export const Modal = (args) => {
  return `
    <button class="" data-micromodal-trigger="yds-modal" role="button"> Demo Modal </button>
    ${modalTwig({ ...modalData, ...args })}
`;
};
