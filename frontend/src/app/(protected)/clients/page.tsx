"use client";

/**
 * Client list — not one of the numbered Sprint 1 screens (S04 is the client *profile* detail
 * view), but the "Clients" nav item needs somewhere to land and GET /clients already exists,
 * so this is a light index screen linking into S04.
 */

import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ApiError } from "@/lib/api/client";
import { createClient, listClients } from "@/lib/api/resources/clients";
import type { Client } from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { formatEgyptianPhone } from "@/lib/format/phone";

const INPUT_CLASSES =
  "rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950";

export default function ClientsPage() {
  const { t } = useLocale();
  const router = useRouter();
  const [clients, setClients] = useState<Client[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");

  const [showNewForm, setShowNewForm] = useState(false);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [address, setAddress] = useState("");
  const [notes, setNotes] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  async function load(q?: string) {
    setLoading(true);
    setError(null);
    try {
      const result = await listClients({ q });
      setClients(result.data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setLoading(false);
    }
  }

  // Intentional fetch-on-mount, see dashboard/page.tsx for rationale.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function resetNewForm() {
    setName("");
    setPhone("");
    setEmail("");
    setAddress("");
    setNotes("");
    setFormError(null);
  }

  async function handleCreate(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    setSubmitting(true);
    try {
      const client = await createClient({
        name,
        phone: phone || undefined,
        email: email || undefined,
        address: address || undefined,
        notes: notes || undefined,
      });
      resetNewForm();
      setShowNewForm(false);
      router.push(`/clients/${client.id}`);
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">
          {t("nav.clients")}
        </h1>
        <div className="flex flex-wrap items-center gap-3">
          <form
            onSubmit={(e) => {
              e.preventDefault();
              load(search);
            }}
          >
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search clients…"
              className={INPUT_CLASSES}
            />
          </form>
          <Button
            type="button"
            onClick={() => {
              setShowNewForm((prev) => !prev);
              setFormError(null);
            }}
          >
            {t("clients.new.cta")}
          </Button>
        </div>
      </div>

      {error ? <ErrorBanner message={error} onRetry={() => load(search)} retryLabel={t("common.retry")} /> : null}

      {showNewForm ? (
        <Card>
          <CardHeader>
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
              {t("clients.new.title")}
            </h2>
          </CardHeader>
          <CardBody>
            <form onSubmit={handleCreate} className="flex flex-col gap-4">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label={t("client.form.name")} htmlFor="name" required>
                  <input
                    id="name"
                    type="text"
                    required
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    className={INPUT_CLASSES}
                  />
                </Field>
                <Field label={t("client.contact.phone")} htmlFor="phone">
                  <input
                    id="phone"
                    type="tel"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    className={INPUT_CLASSES}
                  />
                </Field>
                <Field label={t("client.contact.email")} htmlFor="email">
                  <input
                    id="email"
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    className={INPUT_CLASSES}
                  />
                </Field>
                <Field label={t("client.contact.address")} htmlFor="address">
                  <input
                    id="address"
                    type="text"
                    value={address}
                    onChange={(e) => setAddress(e.target.value)}
                    className={INPUT_CLASSES}
                  />
                </Field>
              </div>
              <Field label={t("client.contact.notes")} htmlFor="notes">
                <textarea
                  id="notes"
                  rows={3}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  className={INPUT_CLASSES}
                />
              </Field>

              {formError ? <ErrorBanner message={formError} /> : null}

              <div className="flex items-center gap-3">
                <Button type="submit" disabled={submitting}>
                  {submitting ? t("clients.new.submitting") : t("clients.new.submit")}
                </Button>
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => {
                    setShowNewForm(false);
                    resetNewForm();
                  }}
                >
                  {t("common.cancel")}
                </Button>
              </div>
            </form>
          </CardBody>
        </Card>
      ) : null}

      <Card>
        <CardBody className="p-0">
          {loading ? (
            <LoadingScreen label={t("common.loading")} />
          ) : clients.length === 0 ? (
            <EmptyState message="No clients yet." />
          ) : (
            <ul className="divide-y divide-zinc-200 dark:divide-zinc-800">
              {clients.map((client) => (
                <li key={client.id}>
                  <Link
                    href={`/clients/${client.id}`}
                    className="flex items-center justify-between gap-4 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                  >
                    <div>
                      <p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
                        {client.name}
                      </p>
                      <p className="text-xs text-zinc-500 dark:text-zinc-400">
                        {formatEgyptianPhone(client.phone)}
                      </p>
                    </div>
                    <div className="text-xs text-zinc-400">
                      {client.properties_count ?? 0} properties · {client.projects_count ?? 0} projects
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>
    </div>
  );
}

function Field({
  label,
  htmlFor,
  required,
  children,
}: {
  label: string;
  htmlFor: string;
  required?: boolean;
  children: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={htmlFor} className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
        {label}
        {required ? <span className="text-red-500"> *</span> : null}
      </label>
      {children}
    </div>
  );
}
