"use client";

/**
 * Media Gallery — org-wide view of every project's attachments (designs, process photos, final
 * pictures), not scoped to one project's Documents tab. Backed by GET /media
 * (ProjectMediaController::galleryIndex()). A single fetch of the full (paginated at 100)
 * result set powers both the filter dropdowns (built from whatever projects/collections are
 * actually present) and the grid itself — filtering happens client-side rather than re-fetching
 * per filter change, since the expected volume here (every project's attachments in one
 * organization) is small enough that a round trip per filter click would be pure overhead.
 *
 * Previews (grid thumbnail and full-size MediaLightbox) use the same authenticated-blob-to-
 * object-URL approach as the Documents tab since files sit behind an authenticated route, not a
 * public URL. Clicking a thumbnail opens MediaLightbox in-page rather than downloading it — an
 * explicit Download action inside the lightbox covers that case. The thumbnail-fetch logic is
 * duplicated with documents/page.tsx's MediaCard rather than extracted, since the two screens'
 * surrounding card chrome (upload button vs project link) differs enough that a shared
 * abstraction would need as many props as it saves lines — MediaLightbox itself IS shared,
 * since it's identical in both places.
 */

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import {
  deleteProjectMedia,
  downloadProjectMedia,
  fetchMediaObjectUrl,
  getOrganizationMedia,
} from "@/lib/api/resources/media";
import type { GalleryMedia, ProjectMediaCollection } from "@/lib/api/types";
import { ApiError } from "@/lib/api/client";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { MediaLightbox } from "@/components/media/MediaLightbox";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { formatDate } from "@/lib/format/date";
import type { Locale } from "@/lib/format/currency";

type T = (key: TranslationKey) => string;
type CollectionFilter = "all" | ProjectMediaCollection;

const INPUT_CLASSES =
  "rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-950";

/** Whether this mime type can be rendered in the grid thumbnail / lightbox at all — see
 *  documents/page.tsx's identical helper for why (must match what MediaLightbox renders). */
function isPreviewable(mimeType: string): boolean {
  return mimeType.startsWith("image/") || mimeType === "application/pdf";
}

export default function MediaGalleryPage() {
  const { t, locale } = useLocale();

  const [media, setMedia] = useState<GalleryMedia[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [collectionFilter, setCollectionFilter] = useState<CollectionFilter>("all");
  const [projectFilter, setProjectFilter] = useState<string>("all");

  async function load() {
    setLoading(true);
    setLoadError(null);
    try {
      const result = await getOrganizationMedia();
      setMedia(result.data);
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setLoading(false);
    }
  }

  // Intentional fetch-on-mount, matching every other screen in this app (see payments/page.tsx).
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, []);

  const projectOptions = useMemo(() => {
    const seen = new Map<string, string>();
    for (const item of media) {
      seen.set(String(item.project.id), item.project.name);
    }
    return Array.from(seen.entries());
  }, [media]);

  const visibleMedia = media.filter((item) => {
    if (collectionFilter !== "all" && item.collection !== collectionFilter) return false;
    if (projectFilter !== "all" && String(item.project.id) !== projectFilter) return false;
    return true;
  });

  // Lifted to page level (rather than per-card) so the lightbox can carousel across every
  // visible item, not just reopen the single card that was clicked.
  const [activeIndex, setActiveIndex] = useState<number | null>(null);

  function handleDeleted(id: GalleryMedia["id"]) {
    setMedia((prev) => prev.filter((m) => String(m.id) !== String(id)));
    setActiveIndex(null);
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">
          {t("mediaGallery.title")}
        </h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("mediaGallery.subtitle")}</p>
      </div>

      {loadError ? <ErrorBanner message={loadError} onRetry={load} retryLabel={t("common.retry")} /> : null}

      {loading ? <LoadingScreen label={t("common.loading")} /> : null}

      {!loading && !loadError ? (
        <Card>
          <CardHeader className="flex flex-wrap items-center gap-3">
            <select
              value={collectionFilter}
              onChange={(e) => setCollectionFilter(e.target.value as CollectionFilter)}
              className={INPUT_CLASSES}
            >
              <option value="all">{t("mediaGallery.filter.allCollections")}</option>
              <option value="designs">{t("documents.collection.designs")}</option>
              <option value="process">{t("documents.collection.process")}</option>
              <option value="final_pictures">{t("documents.collection.final_pictures")}</option>
            </select>

            <select
              value={projectFilter}
              onChange={(e) => setProjectFilter(e.target.value)}
              className={INPUT_CLASSES}
            >
              <option value="all">{t("mediaGallery.filter.allProjects")}</option>
              {projectOptions.map(([id, name]) => (
                <option key={id} value={id}>
                  {name}
                </option>
              ))}
            </select>

            <span className="ms-auto text-xs text-zinc-500 dark:text-zinc-400">
              {t("mediaGallery.count").replace("{count}", String(visibleMedia.length))}
            </span>
          </CardHeader>

          <CardBody>
            {media.length === 0 ? (
              <EmptyState message={t("mediaGallery.empty")} />
            ) : visibleMedia.length === 0 ? (
              <p className="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                {t("mediaGallery.filterEmpty")}
              </p>
            ) : (
              <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                {visibleMedia.map((item, index) => (
                  <GalleryCard
                    key={item.id}
                    media={item}
                    onDeleted={handleDeleted}
                    onOpen={() => setActiveIndex(index)}
                    t={t}
                    locale={locale}
                  />
                ))}
              </div>
            )}
          </CardBody>
        </Card>
      ) : null}

      {activeIndex !== null && visibleMedia[activeIndex] ? (
        <GalleryLightboxController
          items={visibleMedia}
          index={activeIndex}
          onIndexChange={setActiveIndex}
          onClose={() => setActiveIndex(null)}
          t={t}
        />
      ) : null}
    </div>
  );
}

/**
 * Fetches the active item's object URL and renders MediaLightbox with carousel nav wired to
 * move `index` — kept separate from GalleryCard (which still owns its own thumbnail object URL)
 * since the active full-size image and the grid thumbnails have independent lifecycles.
 */
function GalleryLightboxController({
  items,
  index,
  onIndexChange,
  onClose,
  t,
}: {
  items: GalleryMedia[];
  index: number;
  onIndexChange: (index: number) => void;
  onClose: () => void;
  t: T;
}) {
  const media = items[index];
  const [objectUrl, setObjectUrl] = useState<string | null>(null);
  const [downloading, setDownloading] = useState(false);

  useEffect(() => {
    if (!isPreviewable(media.mime_type)) {
      setObjectUrl(null);
      return;
    }

    let cancelled = false;
    let url: string | null = null;

    fetchMediaObjectUrl(media.id)
      .then((created) => {
        if (cancelled) {
          URL.revokeObjectURL(created);
          return;
        }
        url = created;
        setObjectUrl(created);
      })
      .catch(() => {
        // Non-critical: falls back to the "no preview" message inside MediaLightbox.
      });

    return () => {
      cancelled = true;
      if (url) URL.revokeObjectURL(url);
    };
  }, [media.id, media.mime_type]);

  async function handleDownload() {
    setDownloading(true);
    try {
      await downloadProjectMedia(media);
    } finally {
      setDownloading(false);
    }
  }

  return (
    <MediaLightbox
      fileName={media.file_name}
      mimeType={media.mime_type}
      objectUrl={objectUrl}
      caption={media.caption}
      onClose={onClose}
      onDownload={handleDownload}
      downloading={downloading}
      onPrev={index > 0 ? () => onIndexChange(index - 1) : undefined}
      onNext={index < items.length - 1 ? () => onIndexChange(index + 1) : undefined}
      t={t}
      footer={
        <Link
          href={`/projects/${media.project.id}/documents`}
          className="text-zinc-500 underline underline-offset-2 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
        >
          {media.project.name}
        </Link>
      }
    />
  );
}

function GalleryCard({
  media,
  onDeleted,
  onOpen,
  t,
  locale,
}: {
  media: GalleryMedia;
  onDeleted: (id: GalleryMedia["id"]) => void;
  onOpen: () => void;
  t: T;
  locale: Locale;
}) {
  const [objectUrl, setObjectUrl] = useState<string | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!isPreviewable(media.mime_type)) return;

    let cancelled = false;
    let url: string | null = null;

    fetchMediaObjectUrl(media.id)
      .then((created) => {
        if (cancelled) {
          URL.revokeObjectURL(created);
          return;
        }
        url = created;
        setObjectUrl(created);
      })
      .catch(() => {
        // Non-critical: the card falls back to the generic file tile below.
      });

    return () => {
      cancelled = true;
      if (url) URL.revokeObjectURL(url);
    };
  }, [media.id, media.mime_type]);

  async function handleDelete() {
    if (!window.confirm(t("documents.deleteConfirm"))) return;
    setDeleting(true);
    setError(null);
    try {
      await deleteProjectMedia(media.id);
      onDeleted(media.id);
    } catch (err) {
      setError(err instanceof Error ? err.message : t("common.unknownError"));
      setDeleting(false);
    }
  }

  return (
    <div className="flex flex-col gap-1.5 rounded-md border border-zinc-200 p-2 dark:border-zinc-800">
      <button
        type="button"
        onClick={onOpen}
        className="flex aspect-square w-full items-center justify-center overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800"
        title={media.file_name}
      >
        {media.mime_type.startsWith("image/") && objectUrl ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={objectUrl} alt={media.caption ?? media.file_name} className="h-full w-full object-cover" />
        ) : (
          <span className="px-2 text-center text-xs text-zinc-500 dark:text-zinc-400">
            {media.file_name}
          </span>
        )}
      </button>

      <p className="truncate text-xs font-medium text-zinc-700 dark:text-zinc-200" title={media.file_name}>
        {media.caption || media.file_name}
      </p>
      <Link
        href={`/projects/${media.project.id}/documents`}
        className="truncate text-[11px] font-medium text-zinc-500 underline underline-offset-2 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
      >
        {media.project.name}
      </Link>
      <p className="text-[11px] text-zinc-400">{formatDate(media.created_at, locale)}</p>

      {error ? <p className="text-[11px] text-red-600 dark:text-red-400">{error}</p> : null}

      <button
        type="button"
        onClick={handleDelete}
        disabled={deleting}
        className="text-left text-[11px] font-medium text-red-600 hover:underline disabled:opacity-60 dark:text-red-400"
      >
        {deleting ? t("documents.deleting") : t("documents.delete")}
      </button>
    </div>
  );
}
