import styles from "./Footer.module.css";
import { Stack } from "@fluentui/react";
import DOMPurify from "dompurify";
import { getWidgetAttribute } from "../../api";

// The footer intentionally supports links and basic formatting, so it is
// sanitized to a small allowlist rather than escaped outright. The pipe
// separator markup is injected after sanitization so its inline style is not
// stripped.
const ALLOWED_TAGS = ["a", "b", "i", "em", "strong", "br", "span"];
const ALLOWED_ATTR = ["href", "target", "rel", "class"];

const Footer = () => {
  const raw = getWidgetAttribute("data-footer");
  const sanitized = DOMPurify.sanitize(raw, { ALLOWED_TAGS, ALLOWED_ATTR });
  const footerText = sanitized.replace(
    /\|/g,
    "<span style='margin: 0 0.25rem; font-style: normal;'>|</span>"
  );
  return (
    <Stack.Item className={styles.footerContainer}>
      {/* Sanitized above with a restrictive allowlist. */}
      <div
        className={styles.footerText}
        dangerouslySetInnerHTML={{ __html: footerText }}
      ></div>
    </Stack.Item>
  );
};

export default Footer;
