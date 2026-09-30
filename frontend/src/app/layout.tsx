import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import "./globals.css";
import { Providers } from "./providers";

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
    >
      <body className="min-h-full flex flex-col">
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
