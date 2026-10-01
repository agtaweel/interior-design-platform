"use client";

/**
 * S22 (scoped down) — Settings (PROJECT_CONTEXT.md Sprint 8 "Settings"). Two independent
 * sections:
 *
 *  1. Organization profile — PATCH /organizations/{id}, gated behind Permissions::MANAGE_ORGANIZATION
 *     server-side. There is no client-side permissions list anywhere in this app (GET /me only
 *     returns a role *name*, not its permissions_json — see lib/api/resources/organization.ts's
 *     docblock), so this section's visibility is derived from whether loading the current
 *     profile (via a no-op PATCH — see that same docblock) 403s. That single check does double
 *     duty: it both fetches the data to prefill the form AND answers "can this user edit it".
 *
 *  2. Members & roles — GET /organizations/{id}/members needs only an active membership (no
 *     extra gate), so it's always shown; the invite/role-change actions themselves are gated by
 *     MANAGE_MEMBERS server-side and simply surface a 403 ErrorBanner if attempted without it.
 *     Role options for the invite form / per-member role dropdown are derived from the distinct
 *     roles already visible in the members list — there is no `GET /roles` endpoint in this API
 *     (roles are global templates seeded once, see backend RoleSeeder), so this is the only
 *     source of role ids/names available to the frontend.
 */

import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from "react";
import { ApiError } from "@/lib/api/client";
import {
  getOrganizationProfile,
  inviteOrganizationMember,
  listOrganizationMembers,
  updateOrganizationMember,
  updateOrganizationProfile,
} from "@/lib/api/resources/organization";
import { listAuditLogs } from "@/lib/api/resources/auditLogs";
import {
  deleteOrganizationPortfolioMedia,
  getOrganizationMarketplaceProfile,
  listOrganizationPortfolioMedia,
  updateOrganizationMarketplaceProfile,
  uploadOrganizationPortfolioMedia,
  type PortfolioMediaItem,
} from "@/lib/api/resources/organizationMarketplace";
import type {
  AuditLogEntry,
  OrganizationMember,
  OrganizationProfile,
  OrganizationProfileData,
  OrganizationRoleSummary,
} from "@/lib/api/types";
import { useAuth } from "@/lib/auth/AuthContext";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { formatDateTime } from "@/lib/format/date";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { Badge } from "@/components/ui/Badge";

const INPUT_CLASSES =
  "w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950";

export default function SettingsPage() {
  const { t } = useLocale();
  const { currentOrganizationId } = useAuth();

  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("settings.title")}</h1>
      {currentOrganizationId ? (
        <>
          <OrganizationProfileSection organizationId={currentOrganizationId} />
          <MarketplaceProfileSection organizationId={currentOrganizationId} />
          <MembersSection organizationId={currentOrganizationId} />
          <AuditLogSection />
        </>
      ) : (
        <LoadingScreen label={t("common.loading")} />
      )}
    </div>
  );
}

function OrganizationProfileSection({ organizationId }: { organizationId: string }) {
  const { t } = useLocale();
  const [profile, setProfile] = useState<OrganizationProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [forbidden, setForbidden] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [name, setName] = useState("");
  const [legalName, setLegalName] = useState("");
  const [logoUrl, setLogoUrl] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [currency, setCurrency] = useState("");
  const [timezone, setTimezone] = useState("");

  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saveSuccess, setSaveSuccess] = useState(false);

  function applyProfile(p: OrganizationProfile) {
    setProfile(p);
    setName(p.name ?? "");
    setLegalName(p.legal_name ?? "");
    setLogoUrl(p.logo_url ?? "");
    setPhone(p.phone ?? "");
    setEmail(p.email ?? "");
    setCurrency(p.currency ?? "");
    setTimezone(p.timezone ?? "");
  }

  useEffect(() => {
    let cancelled = false;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- reset load state for a fresh organizationId
    setLoading(true);
    setForbidden(false);
    setLoadError(null);
    getOrganizationProfile(organizationId)
      .then((p) => {
        if (cancelled) return;
        applyProfile(p);
      })
      .catch((err) => {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 403) {
          setForbidden(true);
        } else {
          setLoadError(err instanceof ApiError ? err.message : t("settings.profile.loadFailed"));
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- organizationId is the only real dep
  }, [organizationId]);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setSaveError(null);
    setSaveSuccess(false);
    try {
      const updated = await updateOrganizationProfile(organizationId, {
        name,
        legal_name: legalName || null,
        logo_url: logoUrl || null,
        phone: phone || null,
        email: email || null,
        currency: currency || null,
        timezone: timezone || null,
      });
      applyProfile(updated);
      setSaveSuccess(true);
    } catch (err) {
      setSaveError(err instanceof ApiError ? err.message : t("settings.profile.save.failed"));
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return (
      <Card>
        <CardBody>
          <LoadingScreen label={t("common.loading")} />
        </CardBody>
      </Card>
    );
  }

  // Per this file's docblock: MANAGE_ORGANIZATION gating is implemented by hiding this whole
  // section when the prefill load 403s, rather than a client-side permissions flag that doesn't
  // exist anywhere in this app.
  if (forbidden) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
          {t("settings.profile.title")}
        </h2>
      </CardHeader>
      <CardBody>
        {loadError ? <ErrorBanner message={loadError} /> : null}
        {!profile && !loadError ? null : (
          <form onSubmit={handleSubmit} className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label={t("settings.profile.fields.name")} htmlFor="org-name">
                <input id="org-name" className={INPUT_CLASSES} value={name} onChange={(e) => setName(e.target.value)} />
              </Field>
              <Field label={t("settings.profile.fields.legalName")} htmlFor="org-legal-name">
                <input
                  id="org-legal-name"
                  className={INPUT_CLASSES}
                  value={legalName}
                  onChange={(e) => setLegalName(e.target.value)}
                />
              </Field>
              <Field label={t("settings.profile.fields.logoUrl")} htmlFor="org-logo-url">
                <input
                  id="org-logo-url"
                  className={INPUT_CLASSES}
                  value={logoUrl}
                  onChange={(e) => setLogoUrl(e.target.value)}
                />
              </Field>
              <Field label={t("settings.profile.fields.phone")} htmlFor="org-phone">
                <input id="org-phone" className={INPUT_CLASSES} value={phone} onChange={(e) => setPhone(e.target.value)} />
              </Field>
              <Field label={t("settings.profile.fields.email")} htmlFor="org-email">
                <input
                  id="org-email"
                  type="email"
                  className={INPUT_CLASSES}
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                />
              </Field>
              <Field label={t("settings.profile.fields.currency")} htmlFor="org-currency">
                <input
                  id="org-currency"
                  className={INPUT_CLASSES}
                  value={currency}
                  onChange={(e) => setCurrency(e.target.value)}
                />
              </Field>
              <Field label={t("settings.profile.fields.timezone")} htmlFor="org-timezone">
                <input
                  id="org-timezone"
                  className={INPUT_CLASSES}
                  value={timezone}
                  onChange={(e) => setTimezone(e.target.value)}
                />
              </Field>
            </div>

            {saveError ? <ErrorBanner message={saveError} /> : null}
            {saveSuccess ? (
              <p className="text-sm text-green-700 dark:text-green-400">{t("settings.profile.save.success")}</p>
            ) : null}

            <div>
              <Button type="submit" disabled={saving}>
                {saving ? t("settings.profile.save.saving") : t("settings.profile.save.cta")}
              </Button>
            </div>
          </form>
        )}
      </CardBody>
    </Card>
  );
}

/** BRD v4 "Client Marketplace" — staff-side listing profile + portfolio. Same 403-hides-the-
 *  section posture as OrganizationProfileSection (see this file's docblock); the two sections
 *  hit different sub-resources and permission checks even though both currently require
 *  MANAGE_ORGANIZATION. Portfolio photos are only loaded once the profile itself has loaded
 *  successfully, since an org without profile access can't have portfolio access either. */
function MarketplaceProfileSection({ organizationId }: { organizationId: string }) {
  const { t } = useLocale();
  const [loading, setLoading] = useState(true);
  const [forbidden, setForbidden] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [description, setDescription] = useState("");
  const [servicesOffered, setServicesOffered] = useState("");
  const [serviceArea, setServiceArea] = useState("");
  const [isListed, setIsListed] = useState(false);

  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saveSuccess, setSaveSuccess] = useState(false);

  const [portfolio, setPortfolio] = useState<PortfolioMediaItem[]>([]);
  const [portfolioLoading, setPortfolioLoading] = useState(true);
  const [portfolioError, setPortfolioError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [deletingId, setDeletingId] = useState<string | number | null>(null);

  function applyProfile(p: OrganizationProfileData) {
    setDescription(p.description ?? "");
    setServicesOffered((p.services_offered ?? []).join(", "));
    setServiceArea(p.service_area ?? "");
    setIsListed(p.is_marketplace_listed);
  }

  function loadPortfolio() {
    setPortfolioLoading(true);
    setPortfolioError(null);
    listOrganizationPortfolioMedia(organizationId)
      .then((items) => setPortfolio(items))
      .catch((err) => {
        setPortfolioError(err instanceof ApiError ? err.message : t("settings.marketplace.portfolio.loadFailed"));
      })
      .finally(() => setPortfolioLoading(false));
  }

  useEffect(() => {
    let cancelled = false;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- reset load state for a fresh organizationId
    setLoading(true);
    setForbidden(false);
    setLoadError(null);
    getOrganizationMarketplaceProfile(organizationId)
      .then((p) => {
        if (cancelled) return;
        applyProfile(p);
        loadPortfolio();
      })
      .catch((err) => {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 403) {
          setForbidden(true);
        } else {
          setLoadError(err instanceof ApiError ? err.message : t("settings.marketplace.loadFailed"));
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- organizationId is the only real dep
  }, [organizationId]);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setSaveError(null);
    setSaveSuccess(false);
    try {
      const updated = await updateOrganizationMarketplaceProfile(organizationId, {
        description: description || null,
        services_offered: servicesOffered
          .split(",")
          .map((s) => s.trim())
          .filter(Boolean),
        service_area: serviceArea || null,
        is_marketplace_listed: isListed,
      });
      applyProfile(updated);
      setSaveSuccess(true);
    } catch (err) {
      setSaveError(err instanceof ApiError ? err.message : t("settings.marketplace.save.failed"));
    } finally {
      setSaving(false);
    }
  }

  async function handleUpload(e: FormEvent<HTMLInputElement>) {
    const file = e.currentTarget.files?.[0];
    e.currentTarget.value = "";
    if (!file) return;
    setUploading(true);
    setPortfolioError(null);
    try {
      const item = await uploadOrganizationPortfolioMedia(organizationId, file);
      setPortfolio((prev) => [item, ...prev]);
    } catch (err) {
      setPortfolioError(err instanceof Error ? err.message : t("settings.marketplace.portfolio.uploadFailed"));
    } finally {
      setUploading(false);
    }
  }

  async function handleDelete(item: PortfolioMediaItem) {
    setDeletingId(item.id);
    setPortfolioError(null);
    try {
      await deleteOrganizationPortfolioMedia(organizationId, item.id);
      setPortfolio((prev) => prev.filter((m) => m.id !== item.id));
    } catch (err) {
      setPortfolioError(err instanceof Error ? err.message : t("settings.marketplace.portfolio.deleteFailed"));
    } finally {
      setDeletingId(null);
    }
  }

  if (loading) {
    return (
      <Card>
        <CardBody>
          <LoadingScreen label={t("common.loading")} />
        </CardBody>
      </Card>
    );
  }

  if (forbidden) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
          {t("settings.marketplace.title")}
        </h2>
        <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{t("settings.marketplace.subtitle")}</p>
      </CardHeader>
      <CardBody className="flex flex-col gap-6">
        {loadError ? <ErrorBanner message={loadError} /> : null}

        <form onSubmit={handleSubmit} className="flex flex-col gap-4">
          <Field label={t("settings.marketplace.fields.description")} htmlFor="marketplace-description">
            <textarea
              id="marketplace-description"
              rows={3}
              className={INPUT_CLASSES}
              value={description}
              onChange={(e) => setDescription(e.target.value)}
            />
          </Field>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label={t("settings.marketplace.fields.servicesOffered")} htmlFor="marketplace-services">
              <input
                id="marketplace-services"
                className={INPUT_CLASSES}
                value={servicesOffered}
                onChange={(e) => setServicesOffered(e.target.value)}
                placeholder={t("settings.marketplace.fields.servicesOfferedHint")}
              />
            </Field>
            <Field label={t("settings.marketplace.fields.serviceArea")} htmlFor="marketplace-service-area">
              <input
                id="marketplace-service-area"
                className={INPUT_CLASSES}
                value={serviceArea}
                onChange={(e) => setServiceArea(e.target.value)}
              />
            </Field>
          </div>

          <label className="flex items-start gap-2 text-sm text-zinc-700 dark:text-zinc-300">
            <input
              type="checkbox"
              className="mt-0.5"
              checked={isListed}
              onChange={(e) => setIsListed(e.target.checked)}
            />
            <span>
              {t("settings.marketplace.fields.listed")}
              <span className="block text-xs text-zinc-500 dark:text-zinc-400">
                {t("settings.marketplace.fields.listedHint")}
              </span>
            </span>
          </label>

          {saveError ? <ErrorBanner message={saveError} /> : null}
          {saveSuccess ? (
            <p className="text-sm text-green-700 dark:text-green-400">{t("settings.marketplace.save.success")}</p>
          ) : null}

          <div>
            <Button type="submit" disabled={saving}>
              {saving ? t("settings.marketplace.save.saving") : t("settings.marketplace.save.cta")}
            </Button>
          </div>
        </form>

        <div className="flex flex-col gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-800">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
              {t("settings.marketplace.portfolio.title")}
            </h3>
            <label>
              <span className="inline-flex cursor-pointer items-center rounded-md bg-amber-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-600">
                {uploading ? t("settings.marketplace.portfolio.uploading") : t("settings.marketplace.portfolio.upload")}
              </span>
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="hidden"
                disabled={uploading}
                onChange={handleUpload}
              />
            </label>
          </div>

          {portfolioError ? <ErrorBanner message={portfolioError} /> : null}

          {portfolioLoading ? (
            <LoadingScreen label={t("common.loading")} />
          ) : portfolio.length === 0 ? (
            <EmptyState message={t("settings.marketplace.portfolio.empty")} />
          ) : (
            <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
              {portfolio.map((item) => (
                <li key={item.id} className="flex items-center justify-between gap-3 py-2">
                  <span className="truncate text-sm text-zinc-700 dark:text-zinc-300">{item.file_name}</span>
                  <Button
                    variant="secondary"
                    disabled={deletingId === item.id}
                    onClick={() => handleDelete(item)}
                  >
                    {t("settings.marketplace.portfolio.delete")}
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </CardBody>
    </Card>
  );
}

function MembersSection({ organizationId }: { organizationId: string }) {
  const { t } = useLocale();
  const [members, setMembers] = useState<OrganizationMember[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [showInvite, setShowInvite] = useState(false);
  const [inviteEmail, setInviteEmail] = useState("");
  const [inviteName, setInviteName] = useState("");
  const [inviteRoleId, setInviteRoleId] = useState("");
  const [inviting, setInviting] = useState(false);
  const [inviteError, setInviteError] = useState<string | null>(null);
  const [inviteSuccess, setInviteSuccess] = useState(false);

  const [roleUpdating, setRoleUpdating] = useState<string | number | null>(null);
  const [roleError, setRoleError] = useState<string | null>(null);

  const availableRoles = useMemo(() => {
    const map = new Map<string, OrganizationRoleSummary>();
    for (const m of members) {
      if (m.role) map.set(String(m.role.id), m.role);
    }
    return Array.from(map.values());
  }, [members]);

  async function load() {
    setLoading(true);
    setLoadError(null);
    try {
      const result = await listOrganizationMembers(organizationId);
      setMembers(result);
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("settings.members.loadFailed"));
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [organizationId]);

  // Derived rather than effect-synced: defaults to the first known role until the user picks
  // one explicitly, without needing a setState-in-effect just to seed a default.
  const effectiveInviteRoleId = inviteRoleId || (availableRoles.length > 0 ? String(availableRoles[0].id) : "");

  async function handleInvite(e: FormEvent) {
    e.preventDefault();
    setInviting(true);
    setInviteError(null);
    setInviteSuccess(false);
    try {
      await inviteOrganizationMember(organizationId, {
        email: inviteEmail,
        name: inviteName || undefined,
        role_id: effectiveInviteRoleId,
      });
      setInviteEmail("");
      setInviteName("");
      setInviteSuccess(true);
      await load();
    } catch (err) {
      setInviteError(err instanceof ApiError ? err.message : t("settings.members.invite.failed"));
    } finally {
      setInviting(false);
    }
  }

  async function handleRoleChange(member: OrganizationMember, roleId: string) {
    setRoleUpdating(member.id);
    setRoleError(null);
    try {
      const updated = await updateOrganizationMember(organizationId, member.id, { role_id: roleId });
      setMembers((prev) => prev.map((m) => (m.id === member.id ? updated : m)));
    } catch (err) {
      setRoleError(err instanceof ApiError ? err.message : t("settings.members.role.failed"));
    } finally {
      setRoleUpdating(null);
    }
  }

  return (
    <Card>
      <CardHeader className="flex items-center justify-between">
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
          {t("settings.members.title")}
        </h2>
        <Button
          variant="secondary"
          onClick={() => {
            setShowInvite((prev) => !prev);
            setInviteError(null);
            setInviteSuccess(false);
          }}
        >
          {t("settings.members.invite.cta")}
        </Button>
      </CardHeader>
      <CardBody className="flex flex-col gap-4">
        {loadError ? <ErrorBanner message={loadError} onRetry={load} retryLabel={t("common.retry")} /> : null}
        {roleError ? <ErrorBanner message={roleError} /> : null}

        {showInvite ? (
          <form
            onSubmit={handleInvite}
            className="flex flex-col gap-4 rounded-md border border-zinc-200 p-4 dark:border-zinc-800"
          >
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <Field label={t("settings.members.invite.fields.email")} htmlFor="invite-email" required>
                <input
                  id="invite-email"
                  type="email"
                  required
                  className={INPUT_CLASSES}
                  value={inviteEmail}
                  onChange={(e) => setInviteEmail(e.target.value)}
                />
              </Field>
              <Field label={t("settings.members.invite.fields.name")} htmlFor="invite-name">
                <input
                  id="invite-name"
                  className={INPUT_CLASSES}
                  value={inviteName}
                  onChange={(e) => setInviteName(e.target.value)}
                />
              </Field>
              <Field label={t("settings.members.invite.fields.role")} htmlFor="invite-role" required>
                <select
                  id="invite-role"
                  required
                  className={INPUT_CLASSES}
                  value={effectiveInviteRoleId}
                  onChange={(e) => setInviteRoleId(e.target.value)}
                >
                  {availableRoles.map((role) => (
                    <option key={role.id} value={role.id}>
                      {role.name}
                    </option>
                  ))}
                </select>
              </Field>
            </div>

            {inviteError ? <ErrorBanner message={inviteError} /> : null}
            {inviteSuccess ? (
              <p className="text-sm text-green-700 dark:text-green-400">{t("settings.members.invite.success")}</p>
            ) : null}

            <div>
              <Button type="submit" disabled={inviting || !effectiveInviteRoleId}>
                {inviting ? t("settings.members.invite.submitting") : t("settings.members.invite.submit")}
              </Button>
            </div>
          </form>
        ) : null}

        {loading ? (
          <LoadingScreen label={t("common.loading")} />
        ) : members.length === 0 ? (
          <EmptyState message={t("settings.members.empty")} />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm rtl:text-right">
              <thead className="border-b border-zinc-200 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                <tr>
                  <th className="whitespace-nowrap px-4 py-2 font-medium">{t("settings.members.columns.name")}</th>
                  <th className="whitespace-nowrap px-4 py-2 font-medium">{t("settings.members.columns.email")}</th>
                  <th className="whitespace-nowrap px-4 py-2 font-medium">{t("settings.members.columns.role")}</th>
                  <th className="whitespace-nowrap px-4 py-2 font-medium">{t("settings.members.columns.status")}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                {members.map((member) => (
                  <tr key={member.id}>
                    <td className="whitespace-nowrap px-4 py-2 font-medium text-zinc-900 dark:text-zinc-50">
                      {member.user.name}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 text-zinc-500 dark:text-zinc-400">
                      {member.user.email}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2">
                      <select
                        className={`${INPUT_CLASSES} w-auto`}
                        value={member.role ? String(member.role.id) : ""}
                        disabled={roleUpdating === member.id}
                        onChange={(e) => handleRoleChange(member, e.target.value)}
                      >
                        {member.role && !availableRoles.some((r) => String(r.id) === String(member.role!.id)) ? (
                          <option value={String(member.role.id)}>{member.role.name}</option>
                        ) : null}
                        {availableRoles.map((role) => (
                          <option key={role.id} value={role.id}>
                            {role.name}
                          </option>
                        ))}
                      </select>
                      {roleUpdating === member.id ? (
                        <span className="ms-2 text-xs text-zinc-400">{t("settings.members.role.updating")}</span>
                      ) : null}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2">
                      <Badge tone={member.status === "active" ? "green" : member.status === "invited" ? "amber" : "red"}>
                        {member.status}
                      </Badge>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardBody>
    </Card>
  );
}

const AUDIT_ACTION_LABEL_KEY: Record<string, TranslationKey> = {
  created: "settings.auditLog.action.created",
  updated: "settings.auditLog.action.updated",
  deleted: "settings.auditLog.action.deleted",
};

/** "ProposalVersion" -> "Proposal Version" — splits a PascalCase entity_type class basename
 *  into readable words without needing a translation key per audited model. */
function humanizeEntityType(entityType: string): string {
  return entityType.replace(/([a-z])([A-Z])/g, "$1 $2");
}

function AuditLogSection() {
  const { t, locale } = useLocale();
  const [entries, setEntries] = useState<AuditLogEntry[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [forbidden, setForbidden] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);

  function load(targetPage: number) {
    setLoading(true);
    setLoadError(null);
    listAuditLogs({ page: targetPage })
      .then((result) => {
        setEntries(result.data);
        setPage(result.meta.current_page);
        setLastPage(result.meta.last_page);
      })
      .catch((err) => {
        if (err instanceof ApiError && err.status === 403) {
          setForbidden(true);
        } else {
          setLoadError(err instanceof ApiError ? err.message : t("settings.auditLog.loadFailed"));
        }
      })
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Same "hide on 403 rather than a client-side permissions flag" posture as
  // OrganizationProfileSection — see this file's docblock.
  if (forbidden) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("settings.auditLog.title")}</h2>
      </CardHeader>
      <CardBody className="flex flex-col gap-4">
        {loadError ? (
          <ErrorBanner message={loadError} onRetry={() => load(page)} retryLabel={t("common.retry")} />
        ) : null}
        {loading ? (
          <LoadingScreen label={t("common.loading")} />
        ) : entries.length === 0 ? (
          <EmptyState message={t("settings.auditLog.empty")} />
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm rtl:text-right">
                <thead className="border-b border-zinc-200 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                  <tr>
                    <th className="whitespace-nowrap px-4 py-2 font-medium">{t("settings.auditLog.columns.time")}</th>
                    <th className="whitespace-nowrap px-4 py-2 font-medium">{t("settings.auditLog.columns.actor")}</th>
                    <th className="whitespace-nowrap px-4 py-2 font-medium">{t("settings.auditLog.columns.action")}</th>
                    <th className="whitespace-nowrap px-4 py-2 font-medium">{t("settings.auditLog.columns.item")}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                  {entries.map((entry) => (
                    <tr key={entry.id}>
                      <td className="whitespace-nowrap px-4 py-2 text-zinc-500 dark:text-zinc-400">
                        {formatDateTime(entry.created_at, locale)}
                      </td>
                      <td className="whitespace-nowrap px-4 py-2 text-zinc-900 dark:text-zinc-50">
                        {entry.actor?.name ?? t("settings.auditLog.actor.system")}
                      </td>
                      <td className="whitespace-nowrap px-4 py-2">
                        <Badge tone={entry.action === "deleted" ? "red" : entry.action === "created" ? "green" : "blue"}>
                          {AUDIT_ACTION_LABEL_KEY[entry.action] ? t(AUDIT_ACTION_LABEL_KEY[entry.action]) : entry.action}
                        </Badge>
                      </td>
                      <td className="whitespace-nowrap px-4 py-2 text-zinc-500 dark:text-zinc-400">
                        {humanizeEntityType(entry.entity_type)} #{entry.entity_id}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex items-center justify-between">
              <Button variant="secondary" disabled={page <= 1} onClick={() => load(page - 1)}>
                {t("settings.auditLog.pagination.previous")}
              </Button>
              <span className="text-xs text-zinc-500 dark:text-zinc-400">
                {t("settings.auditLog.pagination.pageLabel")} {page} / {lastPage}
              </span>
              <Button variant="secondary" disabled={page >= lastPage} onClick={() => load(page + 1)}>
                {t("settings.auditLog.pagination.next")}
              </Button>
            </div>
          </>
        )}
      </CardBody>
    </Card>
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
      <label htmlFor={htmlFor} className="text-xs font-medium text-zinc-500 dark:text-zinc-400">
        {label}
        {required ? " *" : ""}
      </label>
      {children}
    </div>
  );
}
