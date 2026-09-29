"use client";

/**
 * Full-size in-page viewer for a project attachment, shared by the Documents tab
 * (projects/[id]/documents/page.tsx), the org-wide Media Gallery (media/page.tsx), and the
 * public client-portal gallery (p/client-portal/[token]/gallery/page.tsx) — clicking a
 * thumbnail opens this instead of downloading the file or (for the client portal, previously)
 * navigating to it in a new tab. `objectUrl` is whatever URL the calling card already has for
 * the full-size file — a blob URL from fetchMediaObjectUrl for the two authenticated contexts,
 * or a plain public URL for the client portal; this component never fetches on its own, it just
 * renders bigger.
 *
 * Images render via `<img>`; PDFs via `<embed>` (native browser PDF viewer, no extra
 * dependency). Any other mime type (shouldn't currently occur — StoreProjectMediaRequest only
 * accepts jpg/jpeg/png/webp/pdf) falls back to a "no preview" message with a download action,
 * rather than showing a broken embed.
 *
 * Carousel navigation (onPrev/onNext) is optional — omit both to get the original single-item
 * viewer behavior. When provided, ArrowLeft/ArrowRight also navigate, matching Escape-to-close.
 */

import { useEffect } from "react";
import type { ReactNode } from "react";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

type T = (key: TranslationKey) => string;

export interface MediaLightboxProps {
  fileName: string;
  mimeType: string;
  objectUrl: string | null;
  caption: string | null;
  onClose: () => void;
  /** Omit both onDownload and downloading when this context has no download action (e.g. the
   *  public client portal, which has no authenticated blob-download endpoint to call). */
  onDownload?: () => void;
  downloading?: boolean;
  t: T;
  /** Optional extra line under the title — used by the Gallery to show which project this
   *  file belongs to (a link), omitted on the Documents tab where the project is implied. */
  footer?: ReactNode;
  /** Carousel navigation — omit both to render as a single-item viewer with no arrows. */
  onPrev?: () => void;
  onNext?: () => void;
}

export function MediaLightbox({
  fileName,
  mimeType,
  objectUrl,
  caption,
  onClose,
  onDownload,
  downloading,
  t,
  footer,
  onPrev,
  onNext,
}: MediaLightboxProps) {
  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
      if (e.key === "ArrowLeft" && onPrev) onPrev();
      if (e.key === "ArrowRight" && onNext) onNext();
    }
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [onClose, onPrev, onNext]);

  const isImage = mimeType.startsWith("image/");
  const isPdf = mimeType === "application/pdf";

  return (
    <div
      role="dialog"
      aria-modal="true"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
      onClick={onClose}
    >
      <div
        className="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-zinc-900"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
          <div className="min-w-0">
            <p className="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100">
              {caption || fileName}
            </p>
            {footer ? <div className="mt-0.5 text-xs">{footer}</div> : null}
          </div>
          <div className="flex shrink-0 items-center gap-3">
            {onDownload ? (
              <button
                type="button"
                onClick={onDownload}
                disabled={downloading}
                className="text-sm font-medium text-zinc-600 hover:text-zinc-900 disabled:opacity-60 dark:text-zinc-300 dark:hover:text-zinc-100"
              >
                {downloading ? t("documents.downloading") : t("documents.download")}
              </button>
            ) : null}
            <button
              type="button"
              onClick={onClose}
              aria-label={t("common.close")}
              className="text-xl leading-none text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
            >
              &times;
            </button>
          </div>
        </div>

        <div className="relative flex flex-1 items-center justify-center overflow-auto bg-zinc-100 p-4 dark:bg-black">
          {onPrev ? (
            <button
              type="button"
              onClick={onPrev}
              aria-label={t("common.previous")}
              className="absolute start-2 top-1/2 z-10 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-lg text-white hover:bg-black/60 rtl:rotate-180"
            >
              &lsaquo;
            </button>
          ) : null}

          {!objectUrl ? (
            <p className="text-sm text-zinc-500 dark:text-zinc-400">{t("common.loading")}</p>
          ) : isImage ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={objectUrl} alt={caption ?? fileName} className="max-h-full max-w-full object-contain" />
          ) : isPdf ? (
            <embed src={objectUrl} type="application/pdf" className="h-[75vh] w-full" />
          ) : (
            <p className="text-sm text-zinc-500 dark:text-zinc-400">{t("documents.noPreview")}</p>
          )}

          {onNext ? (
            <button
              type="button"
              onClick={onNext}
              aria-label={t("common.next")}
              className="absolute end-2 top-1/2 z-10 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-lg text-white hover:bg-black/60 rtl:rotate-180"
            >
              &rsaquo;
            </button>
          ) : null}
        </div>
      </div>
    </div>
  );
}
