"use client";

/**
 * S05 — Property.
 *
 * Property facts + linked projects via a single GET /properties/{id}. Confirmed against
 * PropertyController::show() / PropertyResource: the endpoint already eager-loads `client` and
 * `projects` — no backend gap for this screen.
 */

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { ApiError } from "@/lib/api/client";
import { getProperty } from "@/lib/api/resources/properties";
import type { Property } from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusBadge } from "@/components/ui/Badge";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { formatArea } from "@/lib/format/number";

export default function PropertyPage() {
  const params = useParams<{ id: string }>();
  const { t } = useLocale();
  const [property, setProperty] = useState<Property | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      setProperty(await getProperty(params.id));
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
  }, [params.id]);

  if (loading) return <LoadingScreen label={t("common.loading")} />;
  if (error) return <ErrorBanner message={error} onRetry={load} retryLabel={t("common.retry")} />;
  if (!property) return <EmptyState message={t("common.notFound")} />;

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">
          {property.compound ?? property.type ?? t("common.na")}
        </h1>
        {property.client ? (
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            {t("property.owner")}:{" "}
            <Link href={`/clients/${property.client.id}`} className="underline hover:text-zinc-900 dark:hover:text-zinc-100">
              {property.client.name}
            </Link>
          </p>
        ) : null}
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardHeader>
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
              {t("property.facts.title")}
            </h2>
          </CardHeader>
          <CardBody className="flex flex-col gap-3 text-sm">
            <Field label={t("property.facts.type")} value={property.type ?? t("common.na")} />
            <Field label={t("property.facts.compound")} value={property.compound ?? t("common.na")} />
            <Field label={t("property.facts.address")} value={property.address ?? t("common.na")} />
            <Field
              label={t("property.facts.area")}
              value={formatArea(property.area_m2) ?? t("common.na")}
            />
            <Field
              label={t("property.facts.bedrooms")}
              value={property.bedrooms != null ? String(property.bedrooms) : t("common.na")}
            />
            <Field
              label={t("property.facts.bathrooms")}
              value={property.bathrooms != null ? String(property.bathrooms) : t("common.na")}
            />
          </CardBody>
        </Card>

        <div className="lg:col-span-2">
          <Card>
            <CardHeader>
              <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
                {t("property.projects.title")}
              </h2>
            </CardHeader>
            <CardBody className="p-0">
              {!property.projects || property.projects.length === 0 ? (
                <EmptyState message={t("property.projects.empty")} />
              ) : (
                <ul className="divide-y divide-zinc-200 dark:divide-zinc-800">
                  {property.projects.map((project) => (
                    <li key={project.id}>
                      <Link
                        href={`/projects/${project.id}`}
                        className="flex items-center justify-between gap-4 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                      >
                        <div>
                          <p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
                            {project.name}
                          </p>
                          <p className="text-xs text-zinc-500 dark:text-zinc-400">{project.code}</p>
                        </div>
                        <StatusBadge status={project.status} />
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>
        </div>
      </div>
    </div>
  );
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">{label}</p>
      <p className="mt-0.5 text-zinc-900 dark:text-zinc-100">{value}</p>
    </div>
  );
}
