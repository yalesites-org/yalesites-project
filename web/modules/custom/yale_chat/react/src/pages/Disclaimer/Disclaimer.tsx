import styles from "./Disclaimer.module.css";
import { useEffect, useRef } from "react";

const Disclaimer = () => {
  // Create a reference to store the div element
  const disclaimerRef = useRef<HTMLDivElement>(null);
  let disclaimerText = document.getElementById("ai-engine-chat-widget")?.getAttribute('disclaimer') || '';

  // Set the innerHTML of the div element when the component mounts
  useEffect(() => {
    if (disclaimerRef.current) {
      disclaimerRef.current.innerHTML = disclaimerText;
    }
  }, [disclaimerText]);

  return (
    <p className={styles.disclaimer}>
      <em>
        <span ref={disclaimerRef}></span>
      </em>
    </p>
  );
}

export default Disclaimer;
