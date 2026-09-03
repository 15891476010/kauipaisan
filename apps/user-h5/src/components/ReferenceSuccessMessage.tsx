import { useCallback, useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { CheckCircleFilled } from "@ant-design/icons";

export function useReferenceSuccessMessage() {
  const [notice, setNotice] = useState<{ id: number; text: string }>();
  const noticeId = useRef(0);
  const noticeTimer = useRef<number | undefined>(undefined);

  useEffect(
    () => () => {
      if (noticeTimer.current !== undefined) {
        window.clearTimeout(noticeTimer.current);
      }
    },
    [],
  );

  const show = useCallback((text: string) => {
    const id = ++noticeId.current;
    setNotice({ id, text });
    if (noticeTimer.current !== undefined) {
      window.clearTimeout(noticeTimer.current);
    }
    noticeTimer.current = window.setTimeout(() => {
      setNotice((current) => current?.id === id ? undefined : current);
      noticeTimer.current = undefined;
    }, 3_000);
  }, []);

  const holder = notice && typeof document !== "undefined"
    ? createPortal(
        <div className="reference-bet-message" role="status" aria-live="polite">
          <div className="reference-bet-message-notice" key={notice.id}>
            <div className="reference-bet-message-content">
              <CheckCircleFilled className="reference-bet-message-icon reference-bet-message-icon-success" />
              <span>{notice.text}</span>
            </div>
          </div>
        </div>,
        document.body,
      )
    : null;

  return { holder, show };
}
