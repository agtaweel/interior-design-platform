"use client";

/**
 * A short screen recording of one project stage, embedded in /help (docs/SIMPLIFICATION_REVIEW.md
 * P4.2). Recorded from the real app by demo/scripts/record_help_videos.mjs — once with the Arabic
 * interface and once with the English one (`<stage>.<lang>.mp4`, picked by the UI language) —
 * each with Arabic and English subtitles (`<stage>.<lang>.<subLang>.vtt`); the subtitle track in
 * the UI language is on by default.
 *
 * There is no voice-over yet: a narrated recording can replace `<stage>.<lang>.mp4` later without
 * touching this component (keep the file name; keep or drop the .vtt files).
 */

import { useLocale } from "@/lib/i18n/LocaleProvider";

export function StageVideo({ stage, title }: { stage: string; title: string }) {
  const { locale, t } = useLocale();
  const base = `/help/videos/${stage}.${locale}`;

  return (
    <figure className="border-b border-[var(--l-border)] px-5 py-4">
      {/* key: re-mount on language change so the default subtitle track follows the UI. */}
      <video
        key={locale}
        controls
        preload="metadata"
        poster={`${base}.jpg`}
        playsInline
        className="aspect-[16/10] w-full max-w-3xl rounded-md border border-[var(--l-border)] bg-black"
        aria-label={`${t("help.video.label")} — ${title}`}
      >
        <source src={`${base}.mp4`} type="video/mp4" />
        <track kind="subtitles" src={`${base}.ar.vtt`} srcLang="ar" label="العربية" default={locale === "ar"} />
        <track kind="subtitles" src={`${base}.en.vtt`} srcLang="en" label="English" default={locale === "en"} />
      </video>
      <figcaption className="mt-2 text-xs text-[var(--l-muted)]">{t("help.video.caption")}</figcaption>
    </figure>
  );
}
