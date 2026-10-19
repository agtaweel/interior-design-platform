"use client";

/**
 * The client's Extras & Changes in the portal. Since docs/SIMPLIFICATION_REVIEW.md P3.1 a change
 * waiting for the client is approved (with its 6-digit code) or rejected right here — same rules
 * as the per-document link, via ChangeOrderClientDecision on the backend.
 */

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import {
  approveClientPortalChangeOrder,
  getClientPortalChangeOrders,
  rejectClientPortalChangeOrder,
} from "@/lib/api/resources/clientPortal";
import { DecisionBanner, DecisionPanel, type DecisionOutcome } from "@/components/client-decision/DecisionPanel";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { PublicApiError } from "@/lib/api/publicClient";
import type { ClientPortalChangeOrder } from "@/lib/api/clientPortalTypes";
import { formatEGP } from "@/lib/format/currency";
import { formatDate } from "@/lib/format/date";
import { directionFor } from "@/lib/i18n/textDirection";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { PortalLoading, PortalInvalidToken, PortalLoadError, PortalEmpty } from "../PortalStates";

export default function ClientPortalChangeOrdersPage() {
  const params = useParams<{ token: string }>();
  const token = params.token;
  const { t, locale } = useLocale();

  const [phase, setPhase] = useState<"loading" | "loaded" | "invalid-token" | "load-error">("loading");
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [orders, setOrders] = useState<ClientPortalChangeOrder[]>([]);
  const [currency, setCurrency] = useState("EGP");
  const [justDecided, setJustDecided] = useState<Set<string>>(new Set());

  function handleDecided(number: string, outcome: DecisionOutcome) {
    if (outcome === "alreadyDecided") {
      load();
      return;
    }
    setJustDecided((prev) => new Set(prev).add(number));
    setOrders((prev) =>
      prev.map((o) => (o.number === number ? { ...o, status: outcome === "approved" ? "approved" : "rejected" } : o)),
    );
  }

  const load = useCallback(() => {
    setPhase("loading");
    getClientPortalChangeOrders(token)
      .then((result) => {
        setOrders(result.data);
        setCurrency(result.organization?.currency ?? "EGP");
        setPhase("loaded");
      })
      .catch((err: unknown) => {
        if (err instanceof PublicApiError && err.status === 404) {
          setPhase("invalid-token");
          return;
        }
        setErrorMessage(err instanceof PublicApiError ? err.message : t("common.unknownError"));
        setPhase("load-error");
      });
  }, [token, t]);

  useEffect(() => {
    load();
  }, [load]);

  if (phase === "loading") return <PortalLoading message={t("clientPortal.changeOrders.loading")} />;
  if (phase === "invalid-token") return <PortalInvalidToken />;
  if (phase === "load-error") return <PortalLoadError message={errorMessage} onRetry={load} />;
  if (orders.length === 0) return <PortalEmpty message={t("clientPortal.changeOrders.empty")} />;

  return (
    <main className="mx-auto w-full max-w-2xl flex-1 space-y-4 px-4 py-6">
      <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("clientPortal.tabs.changeOrders")}</h1>
      {orders.map((order) => (
        <div
          key={order.number}
          className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
        >
          <div className="flex items-center justify-between">
            <p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">{order.number}</p>
            <p
              className={`text-sm font-semibold ${
                Number(order.price_delta) < 0 ? "text-red-600 dark:text-red-400" : "text-zinc-900 dark:text-zinc-50"
              }`}
            >
              {Number(order.price_delta) >= 0 ? "+" : ""}
              {formatEGP(order.price_delta, locale, currency)}
            </p>
          </div>
          <p dir={directionFor(order.reason)} className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            {order.reason}
          </p>
          <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-500">
            {t(`clientPortal.changeOrders.status.${order.status}` as TranslationKey)}
            {order.sent_at ? ` · ${t("clientPortal.changeOrders.sentPrefix")} ${formatDate(order.sent_at)}` : ""}
          </p>

          <div className="mt-3 divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-zinc-800 dark:border-zinc-800">
            {order.items.map((item, index) => (
              <div key={index} className="py-2">
                <p dir={directionFor(item.description)} className="text-sm text-zinc-800 dark:text-zinc-100">
                  {item.description}
                </p>
                <p className="text-xs text-zinc-500 dark:text-zinc-400">
                  {item.quantity} {item.unit} · {formatEGP(item.line_delta, locale, currency)}
                </p>
              </div>
            ))}
          </div>

          <div className="mt-3">
            {order.status === "sent" ? (
              <DecisionPanel
                kind="changeOrder"
                approve={(input) => approveClientPortalChangeOrder(token, order.number, input)}
                decline={(input) => rejectClientPortalChangeOrder(token, order.number, input)}
                onDecided={(outcome) => handleDecided(order.number, outcome)}
              />
            ) : (
              <DecisionBanner kind="changeOrder" status={order.status} justNow={justDecided.has(order.number)} />
            )}
          </div>
        </div>
      ))}
    </main>
  );
}
