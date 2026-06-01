/**
 * Adds event listeners to launch the Yale Chat widget.
 *
 * Any link with href="#launch-chat" will open the chat modal.
 */
document.addEventListener("DOMContentLoaded", function () {
  const launchLinks = document.querySelectorAll('a[href="#launch-chat"]');
  let tries = 0;

  function triggerChatLaunch() {
    const launchButton = document.getElementById("launch-chat-modal");
    if (launchButton) {
      launchButton.click();
    } else if (tries < 3) {
      tries += 1;
      setTimeout(triggerChatLaunch, 1000);
    } else {
      console.error("Yale Chat: launch button not found after 3 attempts.");
    }
  }

  launchLinks.forEach(function (link) {
    link.classList.add("yale-chatbot");
    link.addEventListener("click", function (event) {
      event.preventDefault();
      triggerChatLaunch();
    });
  });

  if (window.location.hash === "#launch-chat") {
    setTimeout(triggerChatLaunch, 1);
  }
});
