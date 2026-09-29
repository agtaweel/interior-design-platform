"use client";

/**
 * Documents tab — project attachments (designs, process/progress photos, final pictures),
 * backed by Spatie Media Library on the backend (see Project::MEDIA_COLLECTIONS /
 * ProjectMediaController). Single fetch of all media up front (GET /projects/{id}/media),
 * grouped client-side into the three fixed collections — mirrors the fetch-on-mount pattern
 * every other project tab uses (see payments/page.tsx).
 *
 * Files are stored on a private disk and only ever reachable through the authenticated
 * GET /media/{id}/file route — there is no public/guessable URL. Previews (both the grid
 * thumbnail and the full-size MediaLightbox) are therefore fetched as blobs and rendered via
 * object URLs, not plain `<img src="...">`, since a bare `<img>` tag can't attach an
 * Authorization header. Clicking a thumbnail opens the file in MediaLightbox (in-page) rather
 * than downloading it — an explicit Download action inside the lightbox covers that case.
 */

import { useEffect, useRef, useState, type ChangeEvent } from "react";
import { useParams } from "next/navigation";
import {
  deleteProjectMedia,
  downloadProjectMedia,
  fetchMediaObjectUrl,
  getProjectMedia,
  uploadProjectMedia,
} from "@/lib/api/resources/media";
import type { ProjectMedia, ProjectMediaCollection } from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { MediaLightbox } from "@/components/media/MediaLightbox";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { formatDate } from "@/lib/format/date";
import type { Locale } from "@/lib/format/currency";

type T = (key: TranslationKey) => string;

const COLLECTIONS: ProjectMediaCollection[] = ["designs", "process", "final_pictures"];

/** Whether this mime type can be rendered in the grid thumbnail / lightbox at all — must match
 *  what MediaLightbox actually knows how to render (image or PDF). Anything else (shouldn't
 *  currently occur — StoreProjectMediaRequest only accepts jpg/jpeg/png/webp/pdf) skips the
 *  blob fetch entirely and falls back to a filename tile. */
function isPreviewable(mimeType: string): boolean {
  return mimeType.startsWith("image/") || mimeType === "application/pdf";
}

export default function DocumentsPage() {
  const params = useParams<{ id: string }>();
  const projectId = params.id;
  const { t, locale } = useLocale();

  const [media, setMedia] = useState<ProjectMedia[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    setLoadError(null);
    try {
      setMedia(await getProjectMedia(projectId));
    } catch {
      setLoadError(t("documents.loadFailed"));
    } finally {
      setLoading(false);
    }
  }

  // Intentional fetch-on-mount, matching every other screen in this app (see payments/page.tsx).
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  function handleUploaded(created: ProjectMedia) {
    setMedia((prev) => [created, ...prev]);
  }

  function handleDeleted(id: ProjectMedia["id"]) {
    setMedia((prev) => prev.filter((m) => String(m.id) !== String(id)));
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">
          {t("documents.title")}
        </h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("documents.subtitle")}</p>
      </div>

      {loadError ? <ErrorBanner message={loadError} onRetry={load} retryLabel={t("common.retry")} /> : null}

      {loading ? (
        <LoadingScreen label={t("common.loading")} />
      ) : (
        COLLECTIONS.map((collection) => (
          <MediaSection
            key={collection}
            projectId={projectId}
            collection={collection}
            items={media.filter((m) => m.collection === collection)}
            onUploaded={handleUploaded}
            onDeleted={handleDeleted}
            t={t}
            locale={locale}
          />
        ))
      )}
    </div>
  );
}

interface MediaSectionProps {
  projectId: string;
  collection: ProjectMediaCollection;
  items: ProjectMedia[];
  onUploaded: (created: ProjectMedia) => void;
  onDeleted: (id: ProjectMedia["id"]) => void;
  t: T;
  locale: Locale;
}

function MediaSection({ projectId, collection, items, onUploaded, onDeleted, t, locale }: MediaSectionProps) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleFileChange(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;

    setUploading(true);
    setError(null);
    try {
      const created = await uploadProjectMedia(projectId, { collection, file });
      onUploaded(created);
    } catch (err) {
      setError(err instanceof Error ? err.message : t("common.unknownError"));
    } finally {
      setUploading(false);
    }
  }

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p className="font-medium text-zinc-900 dark:text-zinc-100">
            {t(`documents.collection.${collection}` as TranslationKey)}
          </p>
          <p className="text-xs text-zinc-500 dark:text-zinc-400">
            {t(`documents.collection.${collection}.hint` as TranslationKey)}
          </p>
        </div>
        <div>
          <input
            ref={fileInputRef}
            type="file"
            accept="image/jpeg,image/png,image/webp,application/pdf"
            className="hidden"
            onChange={handleFileChange}
          />
          <Button
            type="button"
            variant="secondary"
            className="py-1"
            disabled={uploading}
            onClick={() => fileInputRef.current?.click()}
          >
            {uploading ? t("documents.uploading") : t("documents.upload")}
          </Button>
        </div>
      </CardHeader>

      <CardBody>
        {error ? <ErrorBanner message={error} /> : null}

        {items.length === 0 ? (
          <p className="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
            {t("documents.empty")}
          </p>
        ) : (
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
            {items.map((item) => (
              <MediaCard key={item.id} media={item} onDeleted={onDeleted} t={t} locale={locale} />
            ))}
          </div>
        )}
      </CardBody>
    </Card>
  );
}

function MediaCard({
  media,
  onDeleted,
  t,
  locale,
}: {
  media: ProjectMedia;
  onDeleted: (id: ProjectMedia["id"]) => void;
  t: T;
  locale: Locale;
}) {
  const [objectUrl, setObjectUrl] = useState<string | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showLightbox, setShowLightbox] = useState(false);
  const [downloading, setDownloading] = useState(false);

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

  async function handleDownload() {
    setDownloading(true);
    try {
      await downloadProjectMedia(media);
    } catch {
      setError(t("documents.downloadFailed"));
    } finally {
      setDownloading(false);
    }
  }

  return (
    <div className="flex flex-col gap-1.5 rounded-md border border-zinc-200 p-2 dark:border-zinc-800">
      <button
        type="button"
        onClick={() => setShowLightbox(true)}
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
      <p className="text-[11px] text-zinc-400">
        {formatDate(media.created_at, locale)}
        {media.uploaded_by ? ` · ${media.uploaded_by}` : ""}
      </p>

      {error ? <p className="text-[11px] text-red-600 dark:text-red-400">{error}</p> : null}

      <button
        type="button"
        onClick={handleDelete}
        disabled={deleting}
        className="text-left text-[11px] font-medium text-red-600 hover:underline disabled:opacity-60 dark:text-red-400"
      >
        {deleting ? t("documents.deleting") : t("documents.delete")}
      </button>

      {showLightbox ? (
        <MediaLightbox
          fileName={media.file_name}
          mimeType={media.mime_type}
          objectUrl={objectUrl}
          caption={media.caption}
          onClose={() => setShowLightbox(false)}
          onDownload={handleDownload}
          downloading={downloading}
          t={t}
        />
      ) : null}
    </div>
  );
}
