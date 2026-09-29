/**
 * Shared loading/error states for every `/p/client-portal/[token]/...` page — one look and feel
 * across the whole portal, mirroring `/p/proposals/[token]`'s equivalent inline states.
 */

import { Button } from "@/components/ui/Button";
import { useLocale } from "@/lib/i18n/LocaleProvider";

export function PortalLoading({ message }: { message: string }) {
  return (
    <div className="flex flex-1 items-center justify-center py-24">
      <p className="text-sm text-zinc-500 dark:text-zinc-400">{message}</p>
    </div>
  );
}

export function PortalInvalidToken() {
  const { t } = useLocale();
  return (
    <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-24 text-center">
      <div className="text-4xl">🔗</div>
      <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("clientPortal.invalidToken.title")}</h1>
      <p className="max-w-sm text-sm text-zinc-500 dark:text-zinc-400">{t("clientPortal.invalidToken.body")}</p>
    </div>
  );
}

export function PortalLoadError({ message, onRetry }: { message: string | null; onRetry: () => void }) {
  const { t } = useLocale();
  return (
    <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-24 text-center">
      <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("clientPortal.loadError.title")}</h1>
      <p className="max-w-sm text-sm text-zinc-500 dark:text-zinc-400">{message}</p>
      <Button onClick={onRetry}>{t("common.retry")}</Button>
    </div>
  );
}

export function PortalEmpty({ message }: { message: string }) {
  return (
    <div className="flex flex-1 items-center justify-center px-6 py-24 text-center">
      <p className="text-sm text-zinc-500 dark:text-zinc-400">{message}</p>
    </div>
  );
}
