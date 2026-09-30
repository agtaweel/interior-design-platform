"use client";

/**
 * S04 — Client Profile.
 *
 * Contact info + the client's properties and projects, all from a single GET /clients/{id}
 * call. Confirmed against ClientController::show() / ClientResource: the endpoint already
 * eager-loads `properties` and `projects` on the show route (not on the index/list route), so
 * no extra call is needed here — no backend gap for this screen.
 */

import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { ApiError } from "@/lib/api/client";
import { createProperty, getClient } from "@/lib/api/resources/clients";
import type { Client, PropertyFormInput } from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusBadge } from "@/components/ui/Badge";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { formatEgyptianPhone } from "@/lib/format/phone";

const INPUT_CLASSES =
  "rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950";

const PROPERTY_TYPES = ["apartment", "villa", "office", "retail", "other"] as const;

export default function ClientProfilePage() {
  const params = useParams<{ id: string }>();
  const { t } = useLocale();
  const [client, setClient] = useState<Client | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [showAddProperty, setShowAddProperty] = useState(false);
  const [propertyType, setPropertyType] = useState<PropertyFormInput["type"]>("apartment");
  const [compound, setCompound] = useState("");
  const [address, setAddress] = useState("");
  const [areaM2, setAreaM2] = useState("");
  const [bedrooms, setBedrooms] = useState("");
  const [bathrooms, setBathrooms] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      setClient(await getClient(params.id));
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

  function resetAddPropertyForm() {
    setPropertyType("apartment");
    setCompound("");
    setAddress("");
    setAreaM2("");
    setBedrooms("");
    setBathrooms("");
    setFormError(null);
  }

  async function handleAddProperty(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    setSubmitting(true);
    try {
      await createProperty(params.id, {
        type: propertyType,
        compound: compound || undefined,
        address: address || undefined,
        area_m2: areaM2 ? Number(areaM2) : undefined,
        bedrooms: bedrooms ? Number(bedrooms) : undefined,
        bathrooms: bathrooms ? Number(bathrooms) : undefined,
      });
      resetAddPropertyForm();
      setShowAddProperty(false);
      await load();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  if (loading) return <LoadingScreen label={t("common.loading")} />;
  if (error) return <ErrorBanner message={error} onRetry={load} retryLabel={t("common.retry")} />;
  if (!client) return <EmptyState message={t("common.notFound")} />;

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{client.name}</h1>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardHeader>
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
              {t("client.contact.title")}
            </h2>
          </CardHeader>
          <CardBody className="flex flex-col gap-3 text-sm">
            <Field label={t("client.contact.phone")} value={formatEgyptianPhone(client.phone)} />
            <Field label={t("client.contact.email")} value={client.email ?? t("common.na")} />
            <Field label={t("client.contact.address")} value={client.address ?? t("common.na")} />
            <Field label={t("client.contact.notes")} value={client.notes ?? t("common.na")} />
          </CardBody>
        </Card>

        <div className="flex flex-col gap-6 lg:col-span-2">
          <Card>
            <CardHeader className="flex items-center justify-between">
              <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
                {t("client.properties.title")}
              </h2>
              <Button
                type="button"
                variant="secondary"
                className="py-1"
                onClick={() => {
                  setShowAddProperty((prev) => !prev);
                  setFormError(null);
                }}
              >
                {t("client.properties.new.cta")}
              </Button>
            </CardHeader>
            {showAddProperty ? (
              <CardBody className="border-b border-zinc-200 dark:border-zinc-800">
                <form onSubmit={handleAddProperty} className="flex flex-col gap-4">
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField label={t("property.facts.type")} htmlFor="property-type" required>
                      <select
                        id="property-type"
                        required
                        value={propertyType}
                        onChange={(e) =>
                          setPropertyType(e.target.value as PropertyFormInput["type"])
                        }
                        className={INPUT_CLASSES}
                      >
                        {PROPERTY_TYPES.map((type) => (
                          <option key={type} value={type}>
                            {t(`property.type.${type}` as const)}
                          </option>
                        ))}
                      </select>
                    </FormField>
                    <FormField label={t("property.facts.compound")} htmlFor="property-compound">
                      <input
                        id="property-compound"
                        type="text"
                        value={compound}
                        onChange={(e) => setCompound(e.target.value)}
                        className={INPUT_CLASSES}
                      />
                    </FormField>
                    <FormField label={t("property.facts.address")} htmlFor="property-address">
                      <input
                        id="property-address"
                        type="text"
                        value={address}
                        onChange={(e) => setAddress(e.target.value)}
                        className={INPUT_CLASSES}
                      />
                    </FormField>
                    <FormField label={t("property.facts.area")} htmlFor="property-area">
                      <input
                        id="property-area"
                        type="number"
                        min="0"
                        step="0.01"
                        value={areaM2}
                        onChange={(e) => setAreaM2(e.target.value)}
                        className={INPUT_CLASSES}
                      />
                    </FormField>
                    <FormField label={t("property.facts.bedrooms")} htmlFor="property-bedrooms">
                      <input
                        id="property-bedrooms"
                        type="number"
                        min="0"
                        step="1"
                        value={bedrooms}
                        onChange={(e) => setBedrooms(e.target.value)}
                        className={INPUT_CLASSES}
                      />
                    </FormField>
                    <FormField label={t("property.facts.bathrooms")} htmlFor="property-bathrooms">
                      <input
                        id="property-bathrooms"
                        type="number"
                        min="0"
                        step="1"
                        value={bathrooms}
                        onChange={(e) => setBathrooms(e.target.value)}
                        className={INPUT_CLASSES}
                      />
                    </FormField>
                  </div>

                  {formError ? <ErrorBanner message={formError} /> : null}

                  <div className="flex items-center gap-3">
                    <Button type="submit" disabled={submitting}>
                      {submitting
                        ? t("client.properties.new.submitting")
                        : t("client.properties.new.submit")}
                    </Button>
                    <Button
                      type="button"
                      variant="secondary"
                      onClick={() => {
                        setShowAddProperty(false);
                        resetAddPropertyForm();
                      }}
                    >
                      {t("common.cancel")}
                    </Button>
                  </div>
                </form>
              </CardBody>
            ) : null}
            <CardBody className="p-0">
              {!client.properties || client.properties.length === 0 ? (
                <EmptyState message={t("client.properties.empty")} />
              ) : (
                <ul className="divide-y divide-zinc-200 dark:divide-zinc-800">
                  {client.properties.map((property) => (
                    <li key={property.id}>
                      <Link
                        href={`/properties/${property.id}`}
                        className="flex items-center justify-between gap-4 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                      >
                        <div>
                          <p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
                            {property.compound ?? property.type ?? t("common.na")}
                          </p>
                          <p className="text-xs text-zinc-500 dark:text-zinc-400">
                            {property.address ?? t("common.na")}
                          </p>
                        </div>
                        <span className="text-xs text-zinc-400">{property.type}</span>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
                {t("client.projects.title")}
              </h2>
            </CardHeader>
            <CardBody className="p-0">
              {!client.projects || client.projects.length === 0 ? (
                <EmptyState message={t("client.projects.empty")} />
              ) : (
                <ul className="divide-y divide-zinc-200 dark:divide-zinc-800">
                  {client.projects.map((project) => (
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

function FormField({
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
