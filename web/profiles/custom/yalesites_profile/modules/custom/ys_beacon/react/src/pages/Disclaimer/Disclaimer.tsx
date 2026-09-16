import styles from "./Disclaimer.module.css";
import DOMPurify from "dompurify";
import { getWidgetAttribute } from "../../api";
import { RichTextAllowTags, RichTextAllowAttr } from "../../constants/richTextAllowTags";

interface Props {
  // Stable id so the chat input can reference the disclaimer via aria-describedby.
  id?: string;
}

const Disclaimer = ({ id }: Props) => {
  const raw = getWidgetAttribute("data-disclaimer");
  // The disclaimer intentionally supports links and basic inline formatting, so
  // it is sanitized to the shared small allowlist rather than escaped outright.
  const disclaimerText = DOMPurify.sanitize(raw, {
    ALLOWED_TAGS: RichTextAllowTags,
    ALLOWED_ATTR: RichTextAllowAttr,
  });

  return (
    <p id={id} className={styles.disclaimer}>
      <em>
        {/* Sanitized above with a restrictive allowlist. */}
        <span dangerouslySetInnerHTML={{ __html: disclaimerText }}></span>
      </em>
    </p>
  );
};

export default Disclaimer;
