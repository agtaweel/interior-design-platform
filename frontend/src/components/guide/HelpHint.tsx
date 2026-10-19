"use client";

/**
 * A small "?" next to a field people don't understand at first sight (docs/SIMPLIFICATION_REVIEW.md
 * P4.1). Click opens a one- or two-sentence explanation plus a "Learn more" link into the matching
 * /help glossary entry; closes on outside click or Escape. Copy lives in lib/guide/content.ts
 * (HINTS) next to the glossary it links to.
 */

import { useEffect, useId, useRef, useState } from "react";
import Link from "next/link";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { HINTS, pick, type HintKey } from "@/lib/guide/content";

export function HelpHint({ hint }: { hint: HintKey }) {
  const { locale, t } = useLocale();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLSpanElement>(null);
  const panelId = useId();
  const content = HINTS[hint];

  useEffect(() => {
    if (!open) return;
    function onPointer(e: PointerEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") setOpen(false);
    }
    document.addEventListener("pointerdown", onPointer);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("pointerdown", onPointer);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  return (
    <span ref={ref} className="relative inline-flex align-middle">
      <button
        type="button"
        aria-label={t("hint.label")}
        aria-expanded={open}
        aria-controls={panelId}
        onClick={() => setOpen((o) => !o)}
        className="inline-flex h-4 w-4 items-center justify-center rounded-full border border-zinc-300 text-[10px] font-semibold leading-none text-zinc-500 hover:border-zinc-500 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-950 dark:border-zinc-600 dark:text-zinc-400 dark:hover:text-zinc-100"
      >
        ?
      </button>
      {open ? (
        <span
          id={panelId}
          role="note"
          className="absolute start-0 top-6 z-30 w-72 rounded-md border border-zinc-200 bg-white p-3 text-start text-xs font-normal normal-case leading-relaxed tracking-normal text-zinc-700 shadow-lg dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
        >
          {pick(content.text, locale)}{" "}
          <Link href={`/help#${content.anchor}`} className="font-medium text-zinc-900 underline underline-offset-2 dark:text-zinc-50">
            {t("hint.learnMore")}
          </Link>
        </span>
      ) : null}
    </span>
  );
}
