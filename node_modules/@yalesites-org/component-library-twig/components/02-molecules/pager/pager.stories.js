import pager from './yds-pager.twig';
import inlineMessage from '../inline-message/yds-inline-message.twig';

// Demo JS.
import './cl-pager';

/**
 * Generate pagination data - keep it simple
 */
function generatePagerData(currentPage, totalPages) {
  // Validate and normalize inputs for Storybook demo
  const safeTotalPages = Math.max(
    1,
    Math.min(1000, Math.floor(Math.abs(totalPages || 1))),
  );
  const safeCurrentPage = Math.max(1, Math.floor(Math.abs(currentPage || 1)));

  const data = {
    current: safeCurrentPage,
    items: {
      pages: {},
    },
  };

  for (let i = 1; i <= safeTotalPages; i += 1) {
    data.items.pages[i] = { href: `#page-${i}` };
  }

  if (safeCurrentPage > 1) {
    data.items.previous = { href: `#page-${safeCurrentPage - 1}` };
    data.items.first = { href: '#page-1' };
  }

  if (safeCurrentPage < safeTotalPages) {
    data.items.next = { href: `#page-${safeCurrentPage + 1}` };
    data.items.last = { href: `#page-${safeTotalPages}` };
  }

  return data;
}

/**
 * Global pager management object to avoid circular dependencies
 */
window.PagerManager = {
  updatePager(container, storyId, targetPage, args) {
    try {
      // Clean up the old container - don't modify the parameter directly
      const currentContainer = container;
      if (currentContainer.storybookEnhanced) {
        currentContainer.storybookEnhanced = false;
      }

      // Generate new HTML
      const newData = generatePagerData(targetPage, args.totalPages);
      const newHtml = pager(newData);
      const newWrappedHtml = newHtml.replace(
        '<nav class="pager"',
        `<nav class="pager" data-story-id="${storyId}"`,
      );

      // Update args for next interaction
      const updatedArgs = { ...args, currentPage: targetPage };

      // Add documentation if it exists in the args
      let newDocumentedHtml = newWrappedHtml;
      if (updatedArgs.storyInfo) {
        const messageData = {
          inline_message__type: 'general',
          inline_message__heading: updatedArgs.storyInfo.title,
          inline_message__content: `<strong>Pattern:</strong> ${updatedArgs.storyInfo.pattern}<br><br><strong>Use Case:</strong> ${updatedArgs.storyInfo.useCase}`,
        };

        const docDiv = inlineMessage(messageData);
        newDocumentedHtml = docDiv + newWrappedHtml;
      }

      // Replace the old container content with new content
      currentContainer.parentNode.innerHTML = newDocumentedHtml;

      // Re-attach enhancement after a short delay
      setTimeout(() => {
        this.attachEnhancement(storyId, updatedArgs);
      }, 100);
    } catch (error) {
      // Fallback: just let cl-pager.js handle it visually
      // Silent fallback - no console output in production
    }
  },

  attachEnhancement(storyId, args) {
    const container = document.querySelector(`[data-story-id="${storyId}"]`);
    if (!container) return;

    // Mark this container as enhanced to prevent duplicate listeners
    if (container.storybookEnhanced) return;
    container.storybookEnhanced = true;

    const links = container.querySelectorAll('.pager__link[href^="#page-"]');

    links.forEach((link) => {
      // Prevent multiple event listeners on the same link
      if (link.storybookHandler) return;

      const clickHandler = (e) => {
        // Ensure we prevent any unwanted navigation
        e.preventDefault();
        e.stopPropagation();

        // Let cl-pager.js do its visual update first
        setTimeout(() => {
          const href = link.getAttribute('href');
          const pageMatch = href.match(/#page-(\d+)/);

          if (pageMatch) {
            const targetPage = parseInt(pageMatch[1], 10);

            if (targetPage !== args.currentPage) {
              // Update the component using the global manager
              window.PagerManager.updatePager(
                container,
                storyId,
                targetPage,
                args,
              );
            }
          }
        }, 50); // Longer delay to ensure cl-pager.js finishes
      };

      const newLink = link;
      newLink.storybookHandler = clickHandler;
      link.addEventListener('click', clickHandler, { capture: true });
    });
  },
};

/**
 * Simple wrapper function for the initial enhancement
 */
function addStorybookEnhancement(storyId, args) {
  window.PagerManager.attachEnhancement(storyId, args);
}

/**
 * Storybook Definition
 */
export default {
  title: 'Molecules/Pager',
  argTypes: {
    currentPage: {
      name: 'Current page', // Human-friendly name
      control: { type: 'number', min: 1, max: 50, step: 1 },
      description: 'Current active page',
    },
    totalPages: {
      name: 'Total pages', // Human-friendly name
      control: { type: 'number', min: 1, max: 50, step: 1 },
      description: 'Total number of pages',
    },
    storyInfo: {
      table: { disable: true }, // Hide from controls panel
      control: { disable: true }, // Hide from controls panel
    },
  },
};

/**
 * Template that works WITH cl-pager.js and re-renders when needed
 */
const Template = (args) => {
  // Validate and normalize args for Storybook demo
  const safeTotalPages = Math.max(
    1,
    Math.min(50, Math.floor(Math.abs(args.totalPages || 10))),
  );
  const safeCurrentPage = Math.max(
    1,
    Math.min(safeTotalPages, Math.floor(Math.abs(args.currentPage || 1))),
  );

  const safeArgs = {
    ...args,
    totalPages: safeTotalPages,
    currentPage: safeCurrentPage,
  };

  // Check if single page - show explanatory message instead of pagination
  if (safeArgs.totalPages === 1) {
    const messageData = {
      inline_message__type: 'general',
      inline_message__heading: 'Single Page Detected',
      inline_message__content:
        'Pagination is intentionally hidden when only one page exists. This reduces visual clutter and follows UX best practices.',
    };

    return inlineMessage(messageData);
  }

  const data = generatePagerData(safeArgs.currentPage, safeArgs.totalPages);
  const html = pager(data);

  const storyId = `pager-story-${Date.now()}-${Math.random()}`;
  const wrappedHtml = html.replace(
    '<nav class="pager"',
    `<nav class="pager" data-story-id="${storyId}"`,
  );

  // Add documentation div if story has documentation
  let documentedHtml = wrappedHtml;
  if (safeArgs.storyInfo) {
    const messageData = {
      inline_message__type: 'general',
      inline_message__heading: safeArgs.storyInfo.title,
      inline_message__content: `<strong>Pattern:</strong> ${safeArgs.storyInfo.pattern}<br><br><strong>Use Case:</strong> ${safeArgs.storyInfo.useCase}`,
    };

    const docDiv = inlineMessage(messageData);
    documentedHtml = docDiv + wrappedHtml;
  }

  // Let cl-pager.js attach first, then add our enhancement
  setTimeout(() => {
    addStorybookEnhancement(storyId, safeArgs);
  }, 150);

  return documentedHtml;
};

// All stories - Visual pattern names with use case descriptions
export const Interactive = Template.bind({});
Interactive.args = {
  currentPage: 1,
  totalPages: 10,
};
