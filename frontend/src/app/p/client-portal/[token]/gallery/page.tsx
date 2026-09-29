"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { getClientPortalMedia, clientPortalMediaFileUrl } from "@/lib/api/resources/clientPortal";
import { PublicApiError } from "@/lib/api/publicClient";
import type { ClientPortalMediaItem } from "@/lib/api/clientPortalTypes";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { MediaLightbox } from "@/components/media/MediaLightbox";
import { PortalLoading, PortalInvalidToken, PortalLoadError, PortalEmpty } from "../PortalStates";

const COLLECTION_LABEL_KEY: Record<string, TranslationKey> = {
  designs: "clientPortal.gallery.collection.designs",
  process: "clientPortal.gallery.collection.process",
  final_pictures: "clientPortal.gallery.collection.finalPictures",
};

export default function ClientPortalGalleryPage() {
  const params = useParams<{ token: string }>();
  const token = params.token;
  const { t } = useLocale();

  const [phase, setPhase] = useState<"loading" | "loaded" | "invalid-token" | "load-error">("loading");
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [media, setMedia] = useState<ClientPortalMediaItem[]>([]);
  // Which item the lightbox has open, as an index into `media` — lets the lightbox carousel
  // between every item with Previous/Next instead of each thumbnail navigating away to its own
  // new tab (the previous behavior here).
  const [activeIndex, setActiveIndex] = useState<number | null>(null);

  const load = useCallback(() => {
    setPhase("loading");
    getClientPortalMedia(token)
      .then((result) => {
        setMedia(result);
        setPhase("loaded");
      })
      .catch((err: unknown) => {
        if (err instanceof PublicApiError && err.status === 404) {
          setPhase("invalid-token");
          return;
        }
        setErrorMessage(err instanceof PublicApiError ? err.message : t("common.unknownError"));
        setPhase("load-error");
      });
  }, [token, t]);

  useEffect(() => {
    load();
  }, [load]);

  if (phase === "loading") return <PortalLoading message={t("clientPortal.gallery.loading")} />;
  if (phase === "invalid-token") return <PortalInvalidToken />;
  if (phase === "load-error") return <PortalLoadError message={errorMessage} onRetry={load} />;
  if (media.length === 0) return <PortalEmpty message={t("clientPortal.gallery.empty")} />;

  const activeItem = activeIndex !== null ? media[activeIndex] : null;

  return (
    <main className="mx-auto w-full max-w-2xl flex-1 space-y-3 px-4 py-6">
      <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("clientPortal.tabs.gallery")}</h1>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        {media.map((item, index) => {
          const url = clientPortalMediaFileUrl(token, item.id);
          const isImage = item.mime_type.startsWith("image/");
          return (
            <button
              key={item.id}
              type="button"
              onClick={() => setActiveIndex(index)}
              className="group overflow-hidden rounded-lg border border-zinc-200 bg-white text-start dark:border-zinc-800 dark:bg-zinc-900"
            >
              <div className="flex aspect-square items-center justify-center bg-zinc-100 dark:bg-zinc-800">
                {isImage ? (
                  // eslint-disable-next-line @next/next/no-img-element -- authenticated-by-token file, no next/image domain to configure.
                  <img src={url} alt={item.caption ?? item.file_name} className="h-full w-full object-cover" />
                ) : (
                  <span className="text-3xl">📄</span>
                )}
              </div>
              <p className="truncate px-2 py-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                {COLLECTION_LABEL_KEY[item.collection] ? t(COLLECTION_LABEL_KEY[item.collection]) : item.collection}
              </p>
            </button>
          );
        })}
      </div>

      {activeItem && activeIndex !== null ? (
        <MediaLightbox
          fileName={activeItem.file_name}
          mimeType={activeItem.mime_type}
          objectUrl={clientPortalMediaFileUrl(token, activeItem.id)}
          caption={activeItem.caption}
          onClose={() => setActiveIndex(null)}
          onPrev={activeIndex > 0 ? () => setActiveIndex(activeIndex - 1) : undefined}
          onNext={activeIndex < media.length - 1 ? () => setActiveIndex(activeIndex + 1) : undefined}
          t={t}
        />
      ) : null}
    </main>
  );
}
