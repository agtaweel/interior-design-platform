"use client";

/**
 * Platform Readiness Review finding #04 — the "choose a new password" half of the flow. Reads
 * `token`/`email` from the query string (the link PasswordResetMail sends points here — see
 * PasswordResetService::sendResetLink() on the backend for the exact URL shape).
 */

import { Suspense, useState, type FormEvent } from "react";
import { useSearchParams } from "next/navigation";
import Link from "next/link";
import { ApiError } from "@/lib/api/client";
import { resetPassword } from "@/lib/api/resources/auth";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";

export default function ResetPasswordPage() {
  return (
    <Suspense fallback={null}>
      <ResetPasswordForm />
    </Suspense>
  );
}

function ResetPasswordForm() {
  const { t } = useLocale();
  const searchParams = useSearchParams();
  const token = searchParams.get("token");
  const email = searchParams.get("email");

  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);

    if (password !== passwordConfirmation) {
      setError(t("resetPassword.mismatchError"));
      return;
    }

    setSubmitting(true);
    try {
      await resetPassword({
        email: email!,
        token: token!,
        password,
        password_confirmation: passwordConfirmation,
      });
      setDone(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  const linkInvalid = !token || !email;

  return (
    <div className="flex min-h-screen flex-1 items-center justify-center bg-zinc-50 px-4 dark:bg-black">
      <div className="w-full max-w-sm rounded-lg border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <h1 className="text-xl font-semibold text-zinc-900 dark:text-zinc-50">{t("resetPassword.title")}</h1>

        {linkInvalid ? (
          <ErrorBanner message={t("resetPassword.invalidLink")} />
        ) : done ? (
          <>
            <p className="mt-4 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-900 dark:border-green-900/50 dark:bg-green-950/40 dark:text-green-300">
              {t("resetPassword.successMessage")}
            </p>
            <Link href="/login" className="mt-4 block">
              <Button className="w-full">{t("resetPassword.successCta")}</Button>
            </Link>
          </>
        ) : (
          <>
            <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("resetPassword.subtitle")}</p>
            <form onSubmit={handleSubmit} className="mt-6 flex flex-col gap-4">
              <div className="flex flex-col gap-1">
                <label htmlFor="password" className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                  {t("resetPassword.password")}
                </label>
                <input
                  id="password"
                  type="password"
                  required
                  minLength={8}
                  autoComplete="new-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-950"
                />
              </div>

              <div className="flex flex-col gap-1">
                <label htmlFor="password_confirmation" className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                  {t("resetPassword.passwordConfirmation")}
                </label>
                <input
                  id="password_confirmation"
                  type="password"
                  required
                  minLength={8}
                  autoComplete="new-password"
                  value={passwordConfirmation}
                  onChange={(e) => setPasswordConfirmation(e.target.value)}
                  className="rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-950"
                />
              </div>

              {error ? <ErrorBanner message={error} /> : null}

              <Button type="submit" disabled={submitting} className="mt-2 w-full">
                {submitting ? t("resetPassword.submitting") : t("resetPassword.submit")}
              </Button>
            </form>
          </>
        )}
      </div>
    </div>
  );
}
