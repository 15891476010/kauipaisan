type ToastProps = {
  message: string;
};

export function Toast({ message }: ToastProps) {
  if (!message) return null;
  return (
    <div className="toast toast-error" role="alert">
      <span className="toast-icon" aria-hidden="true">×</span>
      <span className="toast-message">{message}</span>
    </div>
  );
}
