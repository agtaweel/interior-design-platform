"use client";

/**
 * Since docs/SIMPLIFICATION_REVIEW.md P3 the client portal is the one link staff send and the
 * client decides inside it; this per-document page stays for links sent before that change. It is
 * now bilingual (P3.2) and uses the shared decision panel (components/client-decision).
 *
 * S14 — Public Change Order approval page (PRD Sprint 7, docs/PROJECT_CONTEXT.md "Sprint 7
 * scope"). Sibling to `/p/proposals/[token]/page.tsx` — built to the identical isolation
 * architecture and mobile-first pattern; read that file's docblock first if you haven't, the
 * reasoning below is a condensed adaptation of it, not a replacement.
 *
 * ## Isolation from the internal app (same as the proposal portal — read before touching this route)
 *
 * This route lives at `app/p/change-orders/[token]/page.tsx`, OUTSIDE the `(protected)` route
 * group, so `(protected)/layout.tsx`'s auth redirect and `<AppShell>` chrome never apply here —
 * by construction (Next's file-system routing), not a runtime check that could regress.
 *
 * This page does NOT import anything from `lib/auth/*`, does not call `useAuth()`, and does NOT
 * use `lib/api/client.ts`'s `apiFetch`. It talks to the three public endpoints via
 * `lib/api/resources/publicChangeOrders.ts`, which is built on the same `lib/api/publicClient.ts`
 * fetch helper the proposal portal uses (deliberately generic, not proposal-specific — see that
 * file's docblock). The `token` in the URL is the only credential this page ever sends.
 *
 * (The root layout's `LocaleProvider`/`AuthProvider` still wrap this route like every other one
 * in the app — inherited plumbing this page never consumes, same as the proposal portal.)
 *
 * ## Never-leak-internal-linkage verification
 *
 * This screen renders exactly what `GET /public/change-orders/{token}` returns via
 * `PublicChangeOrderData` (see `lib/api/publicChangeOrderTypes.ts`) — no `id`, no `boq_item_id`,
 * no `project_id`/organization/client header info at all (verified live against the real
 * backend response — this endpoint's payload is even more minimal than the proposal portal's,
 * it has no header/org/client/property fields whatsoever). `price_delta` and each item's
 * `old_unit_price`/`new_unit_price`/`line_delta` ARE shown, deliberately — a price delta is the
 * commercial thing being approved here, unlike BOQ internal cost/margin data.
 */

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { LanguageToggle } from "@/components/ui/LanguageToggle";
import { DecisionBanner, DecisionPanel, type DecisionOutcome } from "@/components/client-decision/DecisionPanel";
import {
  approvePublicChangeOrder,
  getPublicChangeOrder,
  rejectPublicChangeOrder,
} from "@/lib/api/resources/publicChangeOrders";
import { PublicApiError } from "@/lib/api/publicClient";
import type { PublicChangeOrderData, PublicChangeOrderStatus } from "@/lib/api/publicChangeOrderTypes";
import { formatDate } from "@/lib/format/date";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import {
  ChangeOrderItemsTable,
  ChangeOrderPriceImpact,
  ChangeOrderReason,
  ChangeOrderTimelineImpact,
} from "./ChangeOrderContentSections";

type Phase = "loading" | "loaded" | "invalid-token" | "load-error";

const STATUS_TONE: Record<PublicChangeOrderStatus, "neutral" | "blue" | "green" | "amber" | "red"> = {
  draft: "neutral",
  sent: "blue",
  approved: "green",
  rejected: "red",
  applied: "amber",
};

export default function ClientChangeOrderPortalPage() {
  const params = useParams<{ token: string }>();
  const token = params.token;
  const { t, locale } = useLocale();

  const [phase, setPhase] = useState<Phase>("loading");
  const [loadErrorMessage, setLoadErrorMessage] = useState<string | null>(null);
  const [data, setData] = useState<PublicChangeOrderData | null>(null);
  const [justDecided, setJustDecided] = useState(false);

  const load = useCallback(() => {
    setPhase("loading");
    setLoadErrorMessage(null);
    getPublicChangeOrder(token)
      .then((result) => {
        setData(result);
        setPhase("loaded");
      })
      .catch((err: unknown) => {
        if (err instanceof PublicApiError && (err.status === 404 || err.code === "change_order_link_invalid")) {
          setPhase("invalid-token");
          return;
        }
        setLoadErrorMessage(err instanceof PublicApiError ? err.message : null);
        setPhase("load-error");
      });
  }, [token]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    if (data) document.title = `${t("publicDoc.changeOrder")} ${data.number}`;
  }, [data, t]);

  function handleDecided(outcome: DecisionOutcome) {
    if (outcome === "alreadyDecided") {
      load();
      return;
    }
    setJustDecided(true);
    setData((prev) => (prev ? { ...prev, status: outcome === "approved" ? "approved" : "rejected" } : prev));
  }

  if (phase === "loading") {
    return (
      <PortalShell>
        <div className="flex flex-1 items-center justify-center py-24">
          <p className="text-sm text-zinc-500 dark:text-zinc-400">{t("clientPortal.changeOrders.loading")}</p>
        </div>
      </PortalShell>
    );
  }

  if (phase === "invalid-token") {
    return (
      <PortalShell>
        <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-24 text-center">
          <div className="text-4xl" aria-hidden="true">🔗</div>
          <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("clientPortal.invalidToken.title")}</h1>
          <p className="max-w-sm text-sm text-zinc-500 dark:text-zinc-400">{t("publicDoc.invalidBody")}</p>
        </div>
      </PortalShell>
    );
  }

  if (phase === "load-error") {
    return (
      <PortalShell>
        <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-24 text-center">
          <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("clientPortal.loadError.title")}</h1>
          {loadErrorMessage ? <p className="max-w-sm text-sm text-zinc-500 dark:text-zinc-400">{loadErrorMessage}</p> : null}
          <Button onClick={load}>{t("common.retry")}</Button>
        </div>
      </PortalShell>
    );
  }

  if (!data) return null;

  return (
    <PortalShell>
      <header className="border-b border-zinc-200 bg-white px-4 py-5 sm:px-6 lg:px-8 dark:border-zinc-800 dark:bg-zinc-900">
        <div className="flex w-full items-start justify-between gap-3">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{t("publicDoc.changeOrder")}</p>
            <div className="mt-1 flex items-center gap-2">
              <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{data.number}</h1>
              <Badge tone={STATUS_TONE[data.status] ?? "neutral"}>
                {t(`clientPortal.changeOrders.status.${data.status}` as TranslationKey)}
              </Badge>
            </div>
          </div>
          <LanguageToggle />
        </div>
      </header>

      <main className="w-full flex-1 space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <DecisionBanner kind="changeOrder" status={data.status} justNow={justDecided} />

        <ChangeOrderReason reason={data.reason} />

        <div className="space-y-3">
          <ChangeOrderPriceImpact priceDelta={data.price_delta} currency={data.organization?.currency} />
          <ChangeOrderTimelineImpact timelineDeltaDays={data.timeline_delta_days} />
        </div>

        <section>
          <h2 className="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{t("publicDoc.items")}</h2>
          <ChangeOrderItemsTable items={data.items} currency={data.organization?.currency} />
        </section>

        {data.status === "sent" ? (
          <DecisionPanel
            kind="changeOrder"
            approve={(input) => approvePublicChangeOrder(token, input)}
            decline={(input) => rejectPublicChangeOrder(token, input)}
            onDecided={handleDecided}
          />
        ) : null}
      </main>

      <footer className="px-4 py-6 text-center text-xs text-zinc-400 dark:text-zinc-600">
        {data.sent_at ? `${t("clientPortal.changeOrders.sentPrefix")} ${formatDate(data.sent_at, locale)}` : ""}
      </footer>
    </PortalShell>
  );
}

function PortalShell({ children }: { children: React.ReactNode }) {
  return <div className="flex min-h-screen flex-col bg-zinc-50 dark:bg-zinc-950">{children}</div>;
}
