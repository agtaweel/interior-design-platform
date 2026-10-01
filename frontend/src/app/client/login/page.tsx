"use client";

/**
 * BRD v4 "Client Marketplace" — mirrors /login's layout/styling exactly, for a ClientUser.
 */

import { Suspense, useEffect, useState, type FormEvent } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import Link from "next/link";
import { ApiError } from "@/lib/api/client";
import { useClientAuth } from "@/lib/auth/ClientAuthContext";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { FitoutLogo } from "@/components/brand/FitoutLogo";
import { ThemeToggle } from "@/components/ui/ThemeToggle";

export default function ClientLoginPage() {
  return (
    <Suspense fallback={null}>
      <ClientLoginForm />
    </Suspense>
  );
}

function ClientLoginForm() {
  const { status, login } = useClientAuth();
  const router = useRouter();
  const searchParams = useSearchParams();
  const organizationId = searchParams.get("organization_id");

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function redirectTarget() {
    return organizationId ? `/marketplace/${organizationId}` : "/client/dashboard";
  }

  useEffect(() => {
    if (status === "authenticated") {
      router.replace(redirectTarget());
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, router]);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await login(email, password);
      router.replace(redirectTarget());
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong. Please try again.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="flex min-h-screen flex-1 flex-col items-center justify-center gap-6 bg-zinc-50 px-4 dark:bg-black">
      <ThemeToggle className="fixed right-4 top-4" />
      <Link href="/marketplace">
        <FitoutLogo />
      </Link>
      <div className="w-full max-w-sm rounded-lg border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <h1 className="text-xl font-semibold text-zinc-900 dark:text-zinc-50">Log in</h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
          Fitout Marketplace — find and message interior design studios.
        </p>

        <form onSubmit={handleSubmit} className="mt-6 flex flex-col gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="email" className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
              Email
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

          <div className="flex flex-col gap-1">
            <label htmlFor="password" className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
              Password
            </label>
            <input
              id="password"
              type="password"
              required
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950"
            />
          </div>

          {error ? <ErrorBanner message={error} /> : null}

          <Button type="submit" disabled={submitting} className="mt-2 w-full">
            {submitting ? "Logging in…" : "Log in"}
          </Button>
        </form>

        <p className="mt-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
          New here?{" "}
          <Link
            href={`/client/signup${organizationId ? `?organization_id=${organizationId}` : ""}`}
            className="font-medium text-zinc-900 hover:underline dark:text-zinc-100"
          >
            Create an account
          </Link>
        </p>
      </div>
    </div>
  );
}
