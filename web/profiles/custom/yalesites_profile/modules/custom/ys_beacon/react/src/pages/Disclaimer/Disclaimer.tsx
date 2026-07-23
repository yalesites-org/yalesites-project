import styles from "./Disclaimer.module.css";
import DOMPurify from "dompurify";
import { getWidgetAttribute } from "../../api";

// The disclaimer intentionally supports links and basic inline formatting
// (mirroring the footer), so it is sanitized to a small allowlist rather than
// escaped outright.
const ALLOWED_TAGS = ["a", "b", "i", "em", "strong", "br", "span"];
const ALLOWED_ATTR = ["href", "target", "rel", "class"];

interface Props {
  // Stable id so the chat input can reference the disclaimer via aria-describedby.
  id?: string;
}

const Disclaimer = ({ id }: Props) => {
  const raw = getWidgetAttribute("data-disclaimer");
  const disclaimerText = DOMPurify.sanitize(raw, { ALLOWED_TAGS, ALLOWED_ATTR });

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
