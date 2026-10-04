"use client";

/** BOQ Master Catalog + Standard Templates — create a new template header (org or platform mount). */

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { ApiError } from "@/lib/api/client";
import { createBoqTemplate } from "@/lib/api/resources/boqTemplates";
import { BOQ_TEMPLATE_TYPES, type BoqTemplateFinishingLevel, type BoqTemplateType } from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { useLocale } from "@/lib/i18n/LocaleProvider";

const INPUT_CLASSES =
  "w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950";

const FINISHING_LEVELS: BoqTemplateFinishingLevel[] = ["BASIC", "STANDARD", "PREMIUM", "LUXURY"];

export function BoqTemplateNewScreen({ platform, basePath }: { platform: boolean; basePath: string }) {
  const { t } = useLocale();
  const router = useRouter();
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [nameEn, setNameEn] = useState("");
  const [nameAr, setNameAr] = useState("");
  const [description, setDescription] = useState("");
  const [templateType, setTemplateType] = useState<BoqTemplateType>("CUSTOM");
  const [finishingLevel, setFinishingLevel] = useState<BoqTemplateFinishingLevel | "">("");
  const [projectType, setProjectType] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const created = await createBoqTemplate(
        {
          code,
          name,
          name_en: nameEn || undefined,
          name_ar: nameAr || undefined,
          description: description || undefined,
          template_type: templateType,
          finishing_level: finishingLevel || undefined,
          project_type: projectType || undefined,
        },
        platform,
      );
      router.push(`${basePath}/${created.id}`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
      setSubmitting(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link href={basePath} className="text-sm text-amber-600 hover:underline dark:text-amber-400">
          ← {t("boqAdmin.back")}
        </Link>
        <h1 className="mt-2 text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("boqAdmin.new")}</h1>
      </div>

      <Card>
        <CardHeader>
          <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("boqAdmin.header.heading")}</h2>
        </CardHeader>
        <CardBody>
          <form onSubmit={handleSubmit} className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label={t("boqAdmin.form.code")}>
                <input className={INPUT_CLASSES} value={code} onChange={(e) => setCode(e.target.value)} required />
              </Field>
              <Field label={t("boqAdmin.form.name")}>
                <input className={INPUT_CLASSES} value={name} onChange={(e) => setName(e.target.value)} required />
              </Field>
              <Field label={t("boqAdmin.form.nameEn")}>
                <input className={INPUT_CLASSES} value={nameEn} onChange={(e) => setNameEn(e.target.value)} />
              </Field>
              <Field label={t("boqAdmin.form.nameAr")}>
                <input className={INPUT_CLASSES} dir="rtl" value={nameAr} onChange={(e) => setNameAr(e.target.value)} />
              </Field>
              <Field label={t("boqAdmin.form.type")}>
                <select
                  className={INPUT_CLASSES}
                  value={templateType}
                  onChange={(e) => setTemplateType(e.target.value as BoqTemplateType)}
                >
                  {BOQ_TEMPLATE_TYPES.map((type) => (
                    <option key={type} value={type}>
                      {type}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label={t("boqAdmin.form.finishingLevel")}>
                <select
                  className={INPUT_CLASSES}
                  value={finishingLevel}
                  onChange={(e) => setFinishingLevel(e.target.value as BoqTemplateFinishingLevel | "")}
                >
                  <option value="">{t("boqAdmin.form.finishingLevelNone")}</option>
                  {FINISHING_LEVELS.map((level) => (
                    <option key={level} value={level}>
                      {level}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label={t("boqAdmin.form.projectType")}>
                <input className={INPUT_CLASSES} value={projectType} onChange={(e) => setProjectType(e.target.value)} />
              </Field>
            </div>
            <Field label={t("boqAdmin.form.description")}>
              <textarea
                className={INPUT_CLASSES}
                rows={3}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
              />
            </Field>
            {error ? <ErrorBanner message={error} /> : null}
            <div>
              <Button type="submit" disabled={submitting}>
                {submitting ? t("boqAdmin.form.creating") : t("boqAdmin.form.create")}
              </Button>
            </div>
          </form>
        </CardBody>
      </Card>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1 text-sm">
      <span className="font-medium text-zinc-700 dark:text-zinc-300">{label}</span>
      {children}
    </label>
  );
}
