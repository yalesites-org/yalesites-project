import styles from "./Disclaimer.module.css";
import { getWidgetAttribute } from "../../api";

const Disclaimer = () => {
  // The disclaimer is plain text ("no markup allowed"), so it is rendered as
  // a React text node — never as innerHTML — which escapes any markup an
  // editor enters.
  const disclaimerText = getWidgetAttribute("data-disclaimer");

  return (
    <p className={styles.disclaimer}>
      <em>
        <span>{disclaimerText}</span>
      </em>
    </p>
  );
};

export default Disclaimer;
