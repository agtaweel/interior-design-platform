"use client";

/**
 * Manual light/dark switch, layered on top of the system-preference default. The actual `.dark`
 * class on <html> is applied twice, deliberately:
 *   1. A blocking inline script in layout.tsx sets it before first paint (reads localStorage,
 *      falls back to `prefers-color-scheme`) — this is what avoids a flash of the wrong theme.
 *   2. This provider's own effect re-applies it whenever `theme` state changes (i.e. after a
 *      toggle) and is also the source of truth once React has mounted.
 * Same "start with a fixed default, correct via effect after mount" tradeoff LocaleProvider
 * makes for hydration-mismatch reasons — see that file's docblock. The one-tick risk here is
 * smaller than it looks: the page's actual colors are already correct immediately (the blocking
 * script set the class before React even mounted); only this provider's own React state — used
 * for the toggle button's icon — might render the wrong icon for a single frame before the
 * effect below corrects it from the DOM.
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

export type Theme = "light" | "dark";

const THEME_STORAGE_KEY = "idp.theme";

interface ThemeContextValue {
  theme: Theme;
  setTheme: (theme: Theme) => void;
  toggleTheme: () => void;
}

const ThemeContext = createContext<ThemeContextValue | null>(null);

/** Inlined verbatim into a blocking <script> in layout.tsx — kept here too so the one script tag
 * and this provider can never drift out of sync on what "the stored/default theme" means. */
export const THEME_INIT_SCRIPT = `
(function () {
  try {
    var stored = localStorage.getItem("${THEME_STORAGE_KEY}");
    var dark = stored ? stored === "dark" : window.matchMedia("(prefers-color-scheme: dark)").matches;
    document.documentElement.classList.toggle("dark", dark);
  } catch (e) {}
})();
`;

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setThemeState] = useState<Theme>("light");

  // Correct from the DOM (already set by the blocking script, see THEME_INIT_SCRIPT) once
  // mounted — mirrors LocaleProvider's own post-mount correction pattern.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setThemeState(document.documentElement.classList.contains("dark") ? "dark" : "light");
  }, []);

  const setTheme = useCallback((next: Theme) => {
    setThemeState(next);
    document.documentElement.classList.toggle("dark", next === "dark");
    window.localStorage.setItem(THEME_STORAGE_KEY, next);
  }, []);

  const toggleTheme = useCallback(() => {
    setTheme(theme === "dark" ? "light" : "dark");
  }, [theme, setTheme]);

  const value = useMemo(() => ({ theme, setTheme, toggleTheme }), [theme, setTheme, toggleTheme]);

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeContextValue {
  const ctx = useContext(ThemeContext);
  if (!ctx) throw new Error("useTheme must be used within a ThemeProvider");
  return ctx;
}
