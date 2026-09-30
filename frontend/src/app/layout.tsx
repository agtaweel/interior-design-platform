import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import Script from "next/script";
import "./globals.css";
import { Providers } from "./providers";
import { THEME_INIT_SCRIPT } from "@/lib/theme/ThemeProvider";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "Fitout",
  description: "Fitout — Interior Design & Finishing Management Platform",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  // lang/dir default to English/LTR for the initial server render; LocaleProvider flips them
  // to Arabic/RTL client-side once a persisted "ar" preference is restored from localStorage
  // (see lib/i18n/LocaleProvider.tsx — there is no locale cookie/routing in Sprint 1, so this
  // one render's worth of "wrong" dir on a returning Arabic user is an accepted tradeoff).
  return (
    <html
      lang="en"
      dir="ltr"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
      // The theme-init script (below) adds/removes "dark" here before React hydrates, so the
      // server-rendered className and the live DOM legitimately differ on this one attribute —
      // expected, not a bug. Same fix next-themes itself uses for this exact scenario.
      suppressHydrationWarning
    >
      <body className="min-h-full flex flex-col">
        {/* `beforeInteractive` runs before hydration/first paint — sets the `.dark` class from
            localStorage (or system preference if nothing's stored) so there's no flash of the
            wrong theme while ThemeProvider itself is still mounting. A plain <script> tag here
            works too but trips React's "script tag inside a component" dev warning; next/script
            is the framework's own mechanism for exactly this pre-hydration use case. See
            ThemeProvider.tsx for the full story. */}
        <Script id="theme-init" strategy="beforeInteractive">
          {THEME_INIT_SCRIPT}
        </Script>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
