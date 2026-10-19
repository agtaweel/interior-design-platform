"use client";

/**
 * One-paste share for a just-sent proposal or change order: a ready WhatsApp message carrying the
 * public link *and* the OTP (previously two separate copy fields the user had to stitch together
 * themselves, shown only once). Two actions: copy the message, or open WhatsApp with it
 * prefilled — straight to the client's chat when the client has a phone number on file.
 *
 * The message is written in the staff member's current UI language. The client's name/phone come
 * from GET /projects/{id} (the send result doesn't carry them); until that resolves the buttons
 * stay disabled rather than sending a message with a blank greeting.
 */

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { getProject } from "@/lib/api/resources/projects";
import type { ClientSummary } from "@/lib/api/types";
import { useAuth } from "@/lib/auth/AuthContext";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { fillTemplate, toWhatsAppNumber, whatsAppUrl } from "@/lib/share/whatsapp";

type Props =
  | { kind: "proposal"; projectId: string; url: string; otp: string }
  | { kind: "changeOrder"; projectId: string; url: string; otp: string; number: string };

export function WhatsAppShare(props: Props) {
  const { t } = useLocale();
  const { organizations, currentOrganizationId } = useAuth();
  // undefined = loading; null = couldn't load (message still works, with a generic greeting).
  const [client, setClient] = useState<ClientSummary | null | undefined>(undefined);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    let cancelled = false;
    getProject(props.projectId)
      .then((project) => !cancelled && setClient(project.client ?? null))
      .catch(() => !cancelled && setClient(null));
    return () => {
      cancelled = true;
    };
  }, [props.projectId]);

  const company =
    organizations.find((m) => String(m.organization.id) === currentOrganizationId)?.organization.name ??
    organizations[0]?.organization.name ??
    "Fitout";

  const message = fillTemplate(
    t(props.kind === "proposal" ? "share.whatsapp.message.proposal" : "share.whatsapp.message.changeOrder"),
    {
      name: client?.name || t("share.whatsapp.fallbackName"),
      company,
      url: props.url,
      otp: props.otp,
      number: props.kind === "changeOrder" ? props.number : "",
    },
  );
  const hasNumber = Boolean(toWhatsAppNumber(client?.phone));
  const ready = client !== undefined;

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(message);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard unavailable (non-secure context) — "Open WhatsApp" and the fields below remain.
    }
  }

  return (
    <div className="flex flex-col gap-2 rounded-md border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-900 dark:bg-emerald-950/40">
      <p className="text-sm text-zinc-700 dark:text-zinc-200">{t("share.whatsapp.hint")}</p>
      <div className="flex flex-wrap gap-2">
        <a
          href={ready ? whatsAppUrl(message, client?.phone) : undefined}
          target="_blank"
          rel="noopener noreferrer"
          aria-disabled={!ready}
          className={`inline-flex items-center justify-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 ${
            ready ? "" : "pointer-events-none opacity-60"
          }`}
        >
          {hasNumber && client
            ? fillTemplate(t("share.whatsapp.openTo"), { name: client.name })
            : t("share.whatsapp.open")}
        </a>
        <Button type="button" variant="secondary" onClick={handleCopy} disabled={!ready}>
          {copied ? t("share.whatsapp.copied") : t("share.whatsapp.copy")}
        </Button>
      </div>
      {ready && !hasNumber ? (
        <p className="text-xs text-zinc-500 dark:text-zinc-400">{t("share.whatsapp.noPhone")}</p>
      ) : null}
    </div>
  );
}
