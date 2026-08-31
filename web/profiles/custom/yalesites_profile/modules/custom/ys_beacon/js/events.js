/**
 * Adds event listeners to launch the chat app.
 *
 * The chat widget is an embedded React app that is initially hidden off
 * screen. Any link with href="#launch-chat" will trigger a click event within
 * this app and open the modal chat window.
 */
document.addEventListener("DOMContentLoaded", function onReady() {
  const launchLinks = document.querySelectorAll('a[href="#launch-chat"]');
  let tries = 0;

  function triggerChatLaunch() {
    const launchButton = document.getElementById("ys-beacon-launch-modal");
    if (launchButton) {
      launchButton.click();
    } else if (tries < 3) {
      tries += 1;
      setTimeout(triggerChatLaunch, 1000);
    } else {
      console.error("Beacon launch button not found after 3 attempts.");
    }
  }

  launchLinks.forEach(function addLaunchHandler(link) {
    link.classList.add("ys-beacon-chatbot");
    link.addEventListener("click", function onLaunchClick(event) {
      event.preventDefault();
      triggerChatLaunch();
    });
  });

  if (window.location.hash === "#launch-chat") {
    setTimeout(triggerChatLaunch, 1);
  }
});
