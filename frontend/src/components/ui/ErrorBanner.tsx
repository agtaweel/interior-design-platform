import { Button } from "@/components/ui/Button";

interface ErrorBannerProps {
  message: string;
  onRetry?: () => void;
  retryLabel?: string;
}

/** Consistent inline error state for API failures, surfaced via ApiError.message. */
export function ErrorBanner({ message, onRetry, retryLabel = "Retry" }: ErrorBannerProps) {
  return (
    <div className="flex items-center justify-between gap-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
      <span>{message}</span>
      {onRetry ? (
        <Button variant="secondary" onClick={onRetry} className="shrink-0 py-1">
          {retryLabel}
        </Button>
      ) : null}
    </div>
  );
}
