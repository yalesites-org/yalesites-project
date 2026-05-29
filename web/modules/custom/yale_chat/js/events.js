/**
 * Adds event listeners to launch the Yale Chat widget.
 *
 * Any link with href="#launch-chat" will open the chat modal.
 */
document.addEventListener('DOMContentLoaded', function () {
  var launchLinks = document.querySelectorAll('a[href="#launch-chat"]');
  var tries = 0;

  launchLinks.forEach(function (link) {
    link.classList.add('yale-chatbot');
    link.addEventListener('click', function (event) {
      event.preventDefault();
      triggerChatLaunch();
    });
  });

  if (window.location.hash === '#launch-chat') {
    setTimeout(triggerChatLaunch, 1);
  }

  function triggerChatLaunch() {
    var launchButton = document.getElementById('launch-chat-modal');
    if (launchButton) {
      launchButton.click();
    }
    else if (tries < 3) {
      tries += 1;
      setTimeout(triggerChatLaunch, 1000);
    }
    else {
      console.error('Yale Chat: launch button not found after 3 attempts.');
    }
  }
});
