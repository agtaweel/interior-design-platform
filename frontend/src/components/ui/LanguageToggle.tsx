"use client";

/**
 * One-tap language switch for the signed-out screens (login, signup, public guide). The signed-in
 * AppShell keeps its <select>; out here a visitor who doesn't read English needs to find Arabic
 * without opening a dropdown, so the button is labelled in the language it switches *to*.
 */

import { useLocale } from "@/lib/i18n/LocaleProvider";

export function LanguageToggle({ className = "" }: { className?: string }) {
  const { locale, setLocale } = useLocale();
  const next = locale === "ar" ? "en" : "ar";

  return (
    <button
      type="button"
      onClick={() => setLocale(next)}
      lang={next}
      className={`inline-flex h-9 items-center rounded-md border border-zinc-300 bg-white px-3 text-sm font-medium text-zinc-900 hover:border-zinc-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:focus-visible:outline-white ${className}`}
    >
      {next === "ar" ? "العربية" : "English"}
    </button>
  );
}
