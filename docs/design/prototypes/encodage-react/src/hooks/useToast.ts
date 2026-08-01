import { useCallback, useEffect, useRef, useState } from 'react';

export function useToast() {
  const [message, setMessage] = useState<string | null>(null);
  const timer = useRef<number>();
  const showToast = useCallback((msg: string) => {
    window.clearTimeout(timer.current);
    setMessage(msg);
    timer.current = window.setTimeout(() => setMessage(null), 2600);
  }, []);
  useEffect(() => () => window.clearTimeout(timer.current), []);
  return { toastMessage: message, showToast };
}
