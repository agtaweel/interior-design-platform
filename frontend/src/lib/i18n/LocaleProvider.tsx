"use client";

/**
 * Root-level i18n provider. Applies `dir="rtl"`/`lang="ar"` on <html> when Arabic is active —
 * wired here (not per-screen) precisely because retrofitting RTL later is expensive (see
 * frontend-engineer agent brief). Sprint 1 only requires English content correctness; Arabic
 * strings exist in the dictionary (see dictionaries.ts) so the toggle is real and testable, but
 * translation quality/completeness is not a Sprint 1 gate.
 *
 * Locale choice is persisted client-side only (localStorage) — there is no per-locale routing
 * (e.g. /en/..., /ar/...) in this sprint, matching the "simple dictionary approach" allowed by
 * the frontend-engineer brief instead of pulling in next-intl for a one-locale-correctness
 * sprint.
 */

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import { dictionaries, type Locale, type TranslationKey } from "@/lib/i18n/dictionaries";

const LOCALE_STORAGE_KEY = "idp.locale";

interface LocaleContextValue {
  locale: Locale;
  dir: "ltr" | "rtl";
  setLocale: (locale: Locale) => void;
  t: (key: TranslationKey) => string;
}

const LocaleContext = createContext<LocaleContextValue | null>(null);

function dirFor(locale: Locale): "ltr" | "rtl" {
  return locale === "ar" ? "rtl" : "ltr";
}

export function LocaleProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>("en");

  // Restore persisted preference on mount (client-only — localStorage isn't available during
  // server rendering, so the first paint is always "en" then may switch, same tradeoff any
  // client-persisted-preference app makes without a locale cookie).
  // Deliberate: restoring a persisted locale preference from localStorage (an external,
  // SSR-unavailable source) *after* mount is what avoids a hydration mismatch here —
  // initializing this state eagerly from localStorage during render would make the
  // server-rendered "en" markup disagree with a returning Arabic user's first client render.
  useEffect(() => {
    const stored = window.localStorage.getItem(LOCALE_STORAGE_KEY);
    if (stored === "en" || stored === "ar") {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setLocaleState(stored);
    }
  }, []);

  useEffect(() => {
    document.documentElement.lang = locale;
    document.documentElement.dir = dirFor(locale);
  }, [locale]);

  const setLocale = useCallback((next: Locale) => {
    setLocaleState(next);
    window.localStorage.setItem(LOCALE_STORAGE_KEY, next);
  }, []);

  const t = useCallback(
    (key: TranslationKey) => dictionaries[locale][key] ?? dictionaries.en[key] ?? key,
    [locale],
  );

  const value = useMemo(
    () => ({ locale, dir: dirFor(locale), setLocale, t }),
    [locale, setLocale, t],
  );

  return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>;
}

export function useLocale(): LocaleContextValue {
  const ctx = useContext(LocaleContext);
  if (!ctx) throw new Error("useLocale must be used within a LocaleProvider");
  return ctx;
}
