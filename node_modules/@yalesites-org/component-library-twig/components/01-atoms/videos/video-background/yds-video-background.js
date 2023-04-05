Drupal.behaviors.videoBG = {
  attach(context) {
    // Selectors
    const items = context.querySelectorAll('.video-background');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    // Classes
    const pauseControl = '.video-background__control--pause';
    const playControl = '.video-background__control--play';

    items.forEach((item) => {
      const video = item.querySelector('video');
      const pauseVideo = item.querySelector(pauseControl);
      const playVideo = item.querySelector(playControl);
      const allowAutoPlay = video.play();

      // set the background video to autoplay if reduceMotion (os-level) is false
      // AND if the browser's built-in autoplay is undefined
      if (allowAutoPlay !== undefined && reduceMotion.matches === false) {
        allowAutoPlay
          .then(() => {
            item.setAttribute('is-playing', true);
            video.setAttribute('autoplay', '');
            video.setAttribute('loop', '');
          })
          .catch(() => {
            item.setAttribute('is-playing', false);
            video.removeAttribute('autoplay', '');
            video.removeAttribute('loop', '');
          });
      } else {
        item.setAttribute('is-playing', false);
        video.pause();
      }

      // manaully control the video
      // pause/play by adding and removing autoplay/loop attributes
      pauseVideo.addEventListener('click', () => {
        video.pause();
        video.removeAttribute('autoplay', '');
        video.removeAttribute('loop', '');
        pauseVideo
          .closest('.video-background')
          .setAttribute('is-playing', false);
      });

      // play, add playing attributes
      playVideo.addEventListener('click', () => {
        video.play();
        video.setAttribute('autoplay', '');
        video.setAttribute('loop', '');
        pauseVideo
          .closest('.video-background')
          .setAttribute('is-playing', true);
      });
    });
  },
};
