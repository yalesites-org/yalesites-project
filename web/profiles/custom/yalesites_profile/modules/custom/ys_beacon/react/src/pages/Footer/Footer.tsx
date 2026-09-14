import styles from "./Footer.module.css";
import { Stack } from "@fluentui/react";
import DOMPurify from "dompurify";
import { getWidgetAttribute } from "../../api";
import { RichTextAllowTags, RichTextAllowAttr } from "../../constants/richTextAllowTags";

const Footer = () => {
  const raw = getWidgetAttribute("data-footer");
  // The footer intentionally supports links and basic formatting, so it is
  // sanitized to the shared small allowlist rather than escaped outright.
  const sanitized = DOMPurify.sanitize(raw, {
    ALLOWED_TAGS: RichTextAllowTags,
    ALLOWED_ATTR: RichTextAllowAttr,
  });
  // The pipe separator markup is injected after sanitization so its inline
  // style is not stripped.
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
