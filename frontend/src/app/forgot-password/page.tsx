"use client";

/**
 * Platform Readiness Review finding #04 — the "forgot password" half of the self-service
 * password reset flow. Mirrors /login's layout/styling exactly for visual consistency.
 *
 * Always shows the same success state regardless of whether the email actually exists — the
 * backend deliberately returns the same generic message either way (see
 * lib/api/resources/auth.ts's forgotPassword() docblock), so this screen never branches on the
 * response content, only on whether the request itself succeeded or failed at the network level.
 */

import { useState, type FormEvent } from "react";
import Link from "next/link";
import { ApiError } from "@/lib/api/client";
import { forgotPassword } from "@/lib/api/resources/auth";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { FitoutLogo } from "@/components/brand/FitoutLogo";
import { ThemeToggle } from "@/components/ui/ThemeToggle";

export default function ForgotPasswordPage() {
  const { t } = useLocale();
  const [email, setEmail] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await forgotPassword(email);
      setSent(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="flex min-h-screen flex-1 flex-col items-center justify-center gap-6 bg-zinc-50 px-4 dark:bg-black">
      <ThemeToggle className="fixed right-4 top-4" />
      <FitoutLogo />
      <div className="w-full max-w-sm rounded-lg border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <h1 className="text-xl font-semibold text-zinc-900 dark:text-zinc-50">{t("forgotPassword.title")}</h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("forgotPassword.subtitle")}</p>

        {sent ? (
          <p className="mt-6 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-900 dark:border-green-900/50 dark:bg-green-950/40 dark:text-green-300">
            {t("forgotPassword.sentMessage")}
          </p>
        ) : (
          <form onSubmit={handleSubmit} className="mt-6 flex flex-col gap-4">
            <div className="flex flex-col gap-1">
              <label htmlFor="email" className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                {t("forgotPassword.email")}
              </label>
              <input
                id="email"
                type="email"
                required
                autoComplete="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950"
              />
            </div>

            {error ? <ErrorBanner message={error} /> : null}

            <Button type="submit" disabled={submitting} className="mt-2 w-full">
              {submitting ? t("forgotPassword.submitting") : t("forgotPassword.submit")}
            </Button>
          </form>
        )}

        <Link
          href="/login"
          className="mt-6 block text-center text-sm text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
        >
          {t("forgotPassword.backToLogin")}
        </Link>
      </div>
    </div>
  );
}
