Drupal.behaviors.ReadTime = {
  attach(context) {
    const mainContent = context.querySelector('#main-content');
    const readTime = context.querySelector('#read_time');
    // Average reading speed in words per minute. This is reportedly on the low end of the average reading speed for adults in the US.
    const wordsPerMinute = 200;

    // Calculate the read time based on the number of words in the main content.
    // Remove extra whitespace and count the number of words.
    const cleanedContent = mainContent.textContent.replace(/\s+/g, ' ').trim();
    const mainContentSplit = cleanedContent.split(' ').length;

    // Calculate the read time in minutes.
    if (mainContentSplit > wordsPerMinute) {
      const minutes = mainContentSplit / wordsPerMinute;
      readTime.textContent = Math.ceil(minutes).toString();
    } else {
      // If the content is less than the average reading speed, display less than 1 minute.
      readTime.textContent = 'less than 1';
    }
  },
};
