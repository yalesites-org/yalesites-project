Drupal.behaviors.mediaGridInteractive = {
  attach(context) {
    const mediaGrids = context.querySelectorAll(
      '.media-grid[data-media-grid-variation="interactive"]',
    );
    const focusableElements =
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
    const body = document.querySelector('body');
    const activeItemIndicator = 'data-media-grid-modal-item-active';
    const ariaCurrent = 'aria-current';

    mediaGrids.forEach((grid) => {
      const items = grid.querySelectorAll('.media-grid__image');
      const maximizeIcons = grid.querySelectorAll('.media-grid__maximize');
      const modal = grid.querySelector('.media-grid__modal');
      const modalMedia = grid.querySelectorAll('.media-grid-modal__media');
      const controls = grid.querySelectorAll('.media-grid-modal__control');
      const itemCount = grid.querySelectorAll('[data-media-grid-item]').length;
      const pagerItems = grid.querySelectorAll(
        '.media-grid-modal__pager-item:not(.media-grid-modal__pager-item--total)',
      );
      let activeIndex;
      let swipeStartX;
      let swipeEndX;

      /**
       * trapKeyboard
       * @description traps keyboard focus when modal is active.
       */
      const trapKeyboard = () => {
        const focusableModalElements =
          modal.querySelectorAll(focusableElements);
        const firstFocusableElement = focusableModalElements[0];
        const lastFocusableElement =
          focusableModalElements[focusableModalElements.length - 1];

        // Set initial focus inside modal when opened.
        firstFocusableElement.focus();

        modal.addEventListener('keydown', (e) => {
          const isTabPressed = e.key === 'Tab' || e.keyCode === 9;

          if (!isTabPressed) {
            return;
          }

          if (e.shiftKey) {
            if (document.activeElement === firstFocusableElement) {
              e.preventDefault();

              lastFocusableElement.focus();
            }
          } else if (document.activeElement === lastFocusableElement) {
            e.preventDefault();

            firstFocusableElement.focus();
          }
        });
      };

      /**
       * toggleModalState
       * @description toggleModalState toggles modal state.
       * @param {Enumerator} currentState the current state the modal is in.
       */
      const toggleModalState = (currentState) => {
        const newState = currentState === 'inactive' ? 'active' : 'inactive';

        grid.setAttribute('data-media-grid-modal-state', newState);

        // On close, set focus on the grid item that was just open in the modal.
        if (newState === 'inactive') {
          grid
            .querySelector(`[data-media-grid-item="${activeIndex}"`)
            .querySelector('button')
            .focus();
          body.removeAttribute('data-modal-active');
        } else if (newState === 'active') {
          trapKeyboard();
          body.setAttribute('data-modal-active', 'true');
        }
      };

      /**
       * indicateActivePager
       * @description visually indicate active pager item.
       */
      const indicateActivePager = (index) => {
        pagerItems.forEach((pagerItem, itemIndex) => {
          pagerItem.removeAttribute(ariaCurrent);

          if (index - 1 === itemIndex) {
            pagerItem.setAttribute(ariaCurrent, true);
          }
        });
      };

      /**
       * showSelectedItem
       * @description showSelectedItem makes the selected item visible in the modal.
       * @param {Number} index the item number of the item to show.
       */
      const showSelectedItem = (index) => {
        activeIndex = index;

        const modalItems = grid.querySelectorAll(
          '[data-media-grid-modal-item]',
        );
        const activeModalItem = grid.querySelectorAll(
          `[data-media-grid-modal-item="${index}"]`,
        );

        // Hide inactive items.
        modalItems.forEach((modalItem) => {
          modalItem.removeAttribute(activeItemIndicator);
        });

        // Show active item.
        activeModalItem.forEach((activeItem) => {
          activeItem.setAttribute(activeItemIndicator, true);
        });

        // Indicate active pager item.
        indicateActivePager(activeIndex);
      };

      /**
       * toggleCaptions
       * @description toggleCaptions truncates long captions visible in the modal.
       */

      // Truncate caption function
      // Take a string and a maxlength parameter
      // slice the string starting at 0 and ending at maxLength.
      function handleCaptionTruncation(captionContent, maxLength) {
        return captionContent.length > maxLength
          ? captionContent.slice(0, maxLength)
          : captionContent;
      }

      // Get all modal content.
      const imageCaptions = grid.querySelectorAll('.media-grid-modal__content');

      // Set variables for each modal content/caption.
      imageCaptions.forEach((imageCaption) => {
        const captionContent = imageCaption.querySelector(
          '.media-grid-modal__text',
        );

        const toggleCaption = imageCaption.querySelector(
          '.media-grid-modal__toggle-caption',
        );

        const captionHeading = imageCaption.querySelector(
          '.media-grid-modal__heading',
        );

        // Check if captionContent exists.
        if (captionContent) {
          // Store the full caption text.
          // if a captionHeading is present, shorten the amount of characters
          // for the caption text.
          const maxLength = 100;

          const fullCaption = captionContent.textContent.trim();

          if (captionHeading) {
            captionContent.classList.add('media-grid-modal__text--has-heading');
            imageCaption.classList.add(
              'media-grid-modal__content--has-heading',
            );
          }
          // Check the length of the caption and truncate if necessary
          if (toggleCaption && fullCaption.length > maxLength) {
            const truncatedCaption = handleCaptionTruncation(
              fullCaption,
              maxLength,
            );

            // set truncated content.
            captionContent.textContent = `${truncatedCaption}...`;

            // toggleCaption: set default attributes
            toggleCaption.setAttribute('aria-expanded', 'false');
            toggleCaption.setAttribute('aria-label', 'expand');
            toggleCaption.style.setProperty('display', 'inline');

            // imageCaption: set default attributes
            imageCaption.setAttribute('aria-expanded', 'false');
            imageCaption.style.setProperty(
              '--modal-content-item-height',
              `${imageCaption.offsetHeight}px`,
            );

            // Toggle the full caption when the "circle plus" toggle is clicked
            if (!body.hasAttribute('gallery-has-click-event')) {
              toggleCaption.addEventListener('click', function _(e) {
                e.preventDefault();
                if (captionContent.textContent === `${truncatedCaption}...`) {
                  toggleCaption.setAttribute('aria-expanded', 'true');
                  toggleCaption.setAttribute('aria-label', 'collapse');
                  captionContent.textContent = fullCaption;
                  imageCaption.setAttribute('aria-expanded', 'true');
                  imageCaption.style.setProperty(
                    '--modal-content-item-height',
                    `${imageCaption.scrollHeight}px`,
                  );
                } else {
                  toggleCaption.setAttribute('aria-expanded', 'false');
                  toggleCaption.setAttribute('aria-label', 'expand');
                  captionContent.textContent = `${truncatedCaption}...`;
                  imageCaption.setAttribute('aria-expanded', 'false');
                  imageCaption.style.setProperty(
                    '--modal-content-item-height',
                    `${imageCaption.offsetHeight}px`,
                  );
                }
              });
            }
          }
        }
      });

      /**
       * handlePagerClick
       * @description Supports pager navigation.
       */
      const handlePagerClick = (index) => {
        showSelectedItem(index);
      };

      /**
       * handleItemClick
       * @description Active modal on item click.
       */
      const handleItemClick = (item) => {
        const index = Number(
          item
            .closest('[data-media-grid-item]')
            .getAttribute('data-media-grid-item'),
        );
        toggleModalState('inactive');
        showSelectedItem(index);
      };

      /**
       * closeModal
       * @description Close the active modal.
       */
      const closeModal = () => {
        toggleModalState('active');
      };

      /**
       * navigateNext
       * @description Navigate to the next item;
       */
      const navigateNext = () => {
        if (activeIndex === itemCount) {
          showSelectedItem(1);
        } else {
          showSelectedItem(activeIndex + 1);
        }
      };

      /**
       * navigatePrevious
       * @description Navigate to the next item;
       */
      const navigatePrevious = () => {
        if (activeIndex === 1) {
          showSelectedItem(itemCount);
        } else {
          showSelectedItem(activeIndex - 1);
        }
      };

      /**
       * handleSwipe
       * @description Support swiping modal items.
       */
      const handleSwipe = () => {
        // If swipe left, navigate to the next item.
        if (swipeEndX < swipeStartX) {
          navigateNext();
          // If swipe right, navigate to the previous item.
        } else if (swipeEndX > swipeStartX) {
          navigatePrevious();
        }
      };

      // Capture the touch start position for the handleSwipe function.
      modal.addEventListener('touchstart', (e) => {
        swipeStartX = e.changedTouches[0].screenX;
      });

      // Capture the touch end position for the handleSwipe function.
      modal.addEventListener('touchend', (e) => {
        swipeEndX = e.changedTouches[0].screenX;
        handleSwipe();
      });

      // Click and drag (with a mouse) support.
      modalMedia.forEach((media) => {
        const Media = media;

        // Capture the mousedown position for the handleSwipe function.
        media.addEventListener('mousedown', (e) => {
          swipeStartX = e.clientX;
        });

        // Capture the mouseup position for the handleSwipe function.
        media.addEventListener('mouseup', (e) => {
          swipeEndX = e.clientX;
          handleSwipe();
        });

        // Disable browser default behaviors that apply to dragging images.
        Media.ondragstart = () => {
          return false;
        };
      });

      // Show modal when an item's image is clicked.
      items.forEach((item) => {
        item.addEventListener('click', () => {
          handleItemClick(item);
        });
      });

      // Show modal when an item's maximize icon is clicked.
      maximizeIcons.forEach((icon) => {
        icon.addEventListener('click', () => {
          handleItemClick(icon);
        });
      });

      // Navigate to selected pager item.
      pagerItems.forEach((pagerItem, index) => {
        pagerItem.addEventListener('click', () => {
          handlePagerClick(index + 1);
        });
      });

      // Handle modal control clicks.
      controls.forEach((control) => {
        control.addEventListener('click', () => {
          switch (true) {
            // Close modal when the "close" button is clicked.
            case /--close/.test(control.className):
              closeModal();
              break;
            // Navigate to the previous item.
            case /--previous/.test(control.className):
              navigatePrevious();
              break;
            // Navigate to the next item.
            case /--next/.test(control.className):
              navigateNext();
              break;
            default:
              break;
          }
        });
      });

      // Close modal when the "backdrop" is clicked.
      grid.addEventListener('click', (e) => {
        const classesToCheck = [
          'media-grid-modal__inner',
          'media-grid-modal__item',
        ];

        if (
          classesToCheck.some((className) =>
            e.target.classList.contains(className),
          )
        ) {
          closeModal();
        }
      });

      // Handle key presses.
      grid.addEventListener(
        'keydown',
        (e) => {
          if (e.defaultPrevented) {
            return;
          }

          switch (e.key) {
            case 'Esc':
            case 'Escape':
              // Close modal on escape key press.
              closeModal();
              break;
            case 'Left':
            case 'ArrowLeft':
              // Navigate to the previous item.
              navigatePrevious();
              break;
            case 'Right':
            case 'ArrowRight':
              // Navigate to the next item.
              navigateNext();
              break;
            default:
              return;
          }

          e.preventDefault();
        },
        true,
      );
    });
  },
};
