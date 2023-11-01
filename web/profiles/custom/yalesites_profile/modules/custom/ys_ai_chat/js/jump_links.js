/**
 * @file
 * JavaScript for alert settings form confirmation modal.
 */

((Drupal) => {
  Drupal.behaviors.ysAiChatJumpLinks = {
    attach: function() { // eslint-disable-line
      console.log(`Here I am`);

      // Select all h2, h3, and h4 elements inside the main element.
      const headings = document.querySelectorAll('main h2, main h3, main h4');

      // Helper function to clean up text for the ID.
      function cleanForID(text) {
        // Replace quotes with hyphens, remove special characters, and spaces
        const cleanedText = text
          .replace(/['"]/g, '-')
          .replace(/[,?]/g, ' ')
          .replace(/\s+/g, '-')
          .replace(/[^a-zA-Z0-9-]+/g, '');

        // Ensure the ID doesn't start with a hyphen or underscore
        return cleanedText.replace(/^-+|-+$/g, '');
      }

      // Loop through each heading element
      headings.forEach((heading, index) => {
        // Get the HTML content of the heading
        const htmlContent = heading.textContent;

        // Remove spaces and convert to lowercase to create an ID
        const id = cleanForID(htmlContent.toLowerCase());

        // Add the index to the ID
        const indexedId = `${id}-${index}`;

        // Set the ID for the heading element
        heading.id = indexedId;
      });
    },
  };
})(Drupal);
