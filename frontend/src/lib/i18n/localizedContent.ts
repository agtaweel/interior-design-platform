/**
 * Bilingual seeded content (BOQ units, catalog categories/items, templates) carries both
 * `name_en` and `name_ar` (and `description_en`/`description_ar`) alongside a legacy single
 * `name`. These helpers pick the field for the active UI locale, falling back to the other
 * language and then to the plain `name` — same precedence as the backend models'
 * `localizedName()`.
 */

import type { Locale, TranslationKey } from "@/lib/i18n/dictionaries";

interface NamedContent {
  name?: string | null;
  name_en?: string | null;
  name_ar?: string | null;
}

interface DescribedContent {
  description?: string | null;
  description_en?: string | null;
  description_ar?: string | null;
}

export function localizedName(entity: NamedContent | null | undefined, locale: Locale): string {
  if (!entity) return "";
  const primary = locale === "ar" ? entity.name_ar : entity.name_en;
  return primary || entity.name || (locale === "ar" ? entity.name_en : entity.name_ar) || "";
}

export function localizedDescription(entity: DescribedContent | null | undefined, locale: Locale): string {
  if (!entity) return "";
  const primary = locale === "ar" ? entity.description_ar : entity.description_en;
  return primary || entity.description || "";
}

type Translate = (key: TranslationKey) => string;

/** Translates an enum-like code via `${prefix}.${code}`, falling back to the raw code when no key exists. */
function translateCode(t: Translate, prefix: string, code: string): string {
  const key = `${prefix}.${code}` as TranslationKey;
  const translated = t(key);
  return translated === key ? code : translated;
}

export const templateTypeLabel = (t: Translate, type: string) => translateCode(t, "boqTemplate.type", type);
export const finishingLevelLabel = (t: Translate, level: string) => translateCode(t, "boqTemplate.level", level);

/**
 * The seeded global roles (RoleSeeder) are stored by their English name only; translate those
 * names here. Any other (organization-defined) role name is shown exactly as typed.
 */
const SYSTEM_ROLE_KEYS: Record<string, TranslationKey> = {
  Owner: "roles.system.owner",
  Admin: "roles.system.admin",
  Designer: "roles.system.designer",
  "Site Staff": "roles.system.siteStaff",
};

export function roleLabel(t: Translate, role: { name: string }): string {
  const key = SYSTEM_ROLE_KEYS[role.name];
  return key ? t(key) : role.name;
}
