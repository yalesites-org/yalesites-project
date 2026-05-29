import styles from "./Footer.module.css";
import { Stack } from "@fluentui/react";

const Footer = () => {
  let footerText = document.getElementById("ai-engine-chat-widget")?.getAttribute('footer') || '';
  footerText = footerText.replace(/\|/g, "<span style='margin: 0 0.25rem; font-style: normal;'>|</span>");
  return (
    <Stack.Item className={styles.footerContainer}>
      {/* Render HTML content directly */}
      <div className={styles.footerText} dangerouslySetInnerHTML={{ __html: footerText }}></div>
    </Stack.Item>
  );
}

export default Footer;
