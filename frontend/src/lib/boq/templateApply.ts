/**
 * Shared pieces of the apply-template(s) flow (POST …/template-preview → …/template-commit), used
 * by the BOQ page's "Apply Templates" dialog and the new-project wizard.
 */

import type { BoqTemplateCommitItem, BoqTemplatePreview, BoqTemplatePreviewCategoryNode, BoqTemplatePreviewItem } from "@/lib/api/types";
import type { Locale } from "@/lib/i18n/dictionaries";
import { localizedName } from "@/lib/i18n/localizedContent";

/** One merged preview row, flattened out of its category tree for simple table rendering. */
export interface FlatPreviewRow {
  categoryId: string;
  item: BoqTemplatePreviewItem;
}

export function flattenPreviewCategories(nodes: BoqTemplatePreviewCategoryNode[]): FlatPreviewRow[] {
  return nodes.flatMap((node) => [
    ...node.items.map((item) => ({ categoryId: String(node.id), item })),
    ...flattenPreviewCategories(node.children),
  ]);
}

/**
 * Builds commit rows from preview rows. `quantityFor`/`unitFor` let the BOQ dialog pass the
 * user's edits; by default each row keeps the template's own quantity and unit. Rows without a
 * positive quantity are skipped, exactly as the dialog does.
 */
export function toCommitItems(
  rows: FlatPreviewRow[],
  locale: Locale,
  quantityFor: (row: FlatPreviewRow) => string = (row) => (row.item.quantity != null ? String(row.item.quantity) : ""),
  unitFor: (row: FlatPreviewRow) => string = (row) => row.item.default_unit?.code ?? "",
): BoqTemplateCommitItem[] {
  return rows
    .map((row) => {
      const quantity = quantityFor(row);
      const unit = unitFor(row);
      if (!quantity || Number(quantity) <= 0) return null;
      const firstContributor = row.item.contributed_by[0];
      const resolved: BoqTemplateCommitItem = {
        catalog_item_id: row.item.catalog_item_id,
        category_id: row.categoryId,
        quantity,
        source_template_id: firstContributor?.template_id ?? null,
        source_template_version_id: firstContributor?.template_version_id ?? null,
        source_template_item_id: firstContributor?.template_item_id ?? null,
        name: localizedName(row.item, locale),
      };
      if (unit) resolved.unit = unit;
      return resolved;
    })
    .filter((item): item is BoqTemplateCommitItem => item !== null);
}

/** Template defaults straight through — what the wizard applies without a review step. */
export function defaultCommitItems(preview: BoqTemplatePreview, locale: Locale): BoqTemplateCommitItem[] {
  return toCommitItems(flattenPreviewCategories(preview.categories), locale);
}
