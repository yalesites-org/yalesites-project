import styles from "./Disclaimer.module.css";
import { getWidgetAttribute } from "../../api";

interface Props {
  // Stable id so the chat input can reference the disclaimer via aria-describedby.
  id?: string;
}

const Disclaimer = ({ id }: Props) => {
  // The disclaimer is plain text ("no markup allowed"), so it is rendered as
  // a React text node — never as innerHTML — which escapes any markup an
  // editor enters.
  const disclaimerText = getWidgetAttribute("data-disclaimer");

  return (
    <p id={id} className={styles.disclaimer}>
      <em>
        <span>{disclaimerText}</span>
      </em>
    </p>
  );
};

export default Disclaimer;
