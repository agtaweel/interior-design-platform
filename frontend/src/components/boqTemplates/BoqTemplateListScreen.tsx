"use client";

/**
 * BOQ Master Catalog + Standard Templates — admin template list, shared between the org-level
 * screen (Settings > BOQ Templates, `platform=false`) and the platform-owner screen
 * (Platform > BOQ Templates, `platform=true`). Both screens call the exact same
 * listBoqTemplates/duplicateBoqTemplate functions with a different `platform` flag — mirrors the
 * backend's BoqTemplateAdminController dual-mount design (same controller, different middleware)
 * rather than maintaining two near-identical list pages.
 */

import { useEffect, useState } from "react";
import Link from "next/link";
import { ApiError } from "@/lib/api/client";
import { duplicateBoqTemplate, listBoqTemplates } from "@/lib/api/resources/boqTemplates";
import type { BoqTemplate } from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { useLocale } from "@/lib/i18n/LocaleProvider";

export function BoqTemplateListScreen({ platform, basePath }: { platform: boolean; basePath: string }) {
  const { t } = useLocale();
  const [templates, setTemplates] = useState<BoqTemplate[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [duplicatingId, setDuplicatingId] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      setTemplates(await listBoqTemplates(platform));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleDuplicate(id: string) {
    setDuplicatingId(id);
    setError(null);
    try {
      const copy = await duplicateBoqTemplate(id, platform);
      setTemplates((prev) => (prev ? [...prev, copy] : [copy]));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setDuplicatingId(null);
    }
  }

  if (loading) return <LoadingScreen label={t("common.loading")} />;
  if (error) return <ErrorBanner message={error} onRetry={load} retryLabel={t("common.retry")} />;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("boqAdmin.title")}</h1>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            {platform ? t("boqAdmin.subtitlePlatform") : t("boqAdmin.subtitleOrg")}
          </p>
        </div>
        <Link href={`${basePath}/new`}>
          <Button>{t("boqAdmin.new")}</Button>
        </Link>
      </div>

      <Card>
        <CardHeader>
          <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("boqAdmin.list.heading")}</h2>
        </CardHeader>
        <CardBody className="p-0">
          {templates && templates.length === 0 ? (
            <div className="p-4">
              <EmptyState message={t("boqAdmin.list.empty")} />
            </div>
          ) : (
            <div className="divide-y divide-zinc-100 dark:divide-zinc-800">
              {templates?.map((tpl) => (
                <div key={tpl.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                  <div className="min-w-[200px] flex-1">
                    <Link
                      href={`${basePath}/${tpl.id}`}
                      className="text-sm font-medium text-zinc-900 hover:underline dark:text-zinc-50"
                    >
                      {tpl.name}
                    </Link>
                    <p className="text-xs text-zinc-500 dark:text-zinc-400">
                      {tpl.code} · {tpl.template_type}
                      {tpl.finishing_level ? ` · ${tpl.finishing_level}` : ""}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    {tpl.active_version ? (
                      <Badge tone={tpl.active_version.status === "published" ? "green" : "neutral"}>
                        v{tpl.active_version.version_number} · {tpl.active_version.status}
                      </Badge>
                    ) : (
                      <Badge tone="amber">{t("boqAdmin.noVersion")}</Badge>
                    )}
                    {!tpl.is_active ? <Badge tone="red">{t("boqAdmin.inactive")}</Badge> : null}
                    <span className="text-xs text-zinc-400">
                      {t("boqAdmin.usageCount").replace("{count}", String(tpl.applications_count ?? 0))}
                    </span>
                    <Button
                      variant="secondary"
                      className="py-1"
                      onClick={() => handleDuplicate(String(tpl.id))}
                      disabled={duplicatingId === String(tpl.id)}
                    >
                      {duplicatingId === String(tpl.id) ? t("boqAdmin.duplicating") : t("boqAdmin.duplicate")}
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
