/**
 * Shared TypeScript types mirroring the backend's JsonResource shapes exactly
 * (see backend/app/Http/Resources/*.php). Keep this file in sync when a resource's
 * `toArray()` shape changes — it's the single source of truth for what the frontend
 * expects the API to return.
 */

export type UUID = string;
export type ISODateString = string;

// ---------------------------------------------------------------------------
// Auth / users (AuthController)
// ---------------------------------------------------------------------------

export interface UserPayload {
  id: number | string;
  name: string;
  email: string;
  phone: string | null;
  status: string;
  /** BRD v3 §5/§20 "Platform Owner" — routes this user to /platform's cross-tenant dashboard. */
  is_platform_owner: boolean;
}

/** Minimal user payload embedded in Project/ProjectMember resources. */
export interface UserSummary {
  id: number | string;
  name: string;
  email: string;
}

export interface OrganizationMembershipSummary {
  organization: {
    id: number | string;
    name: string;
    /** Platform Readiness Review finding #06 — the org's actual currency, e.g. "EGP"/"USD". */
    currency: string;
  };
  role: {
    id: number | string;
    name: string;
  } | null;
  status: string;
}

export interface LoginResponseData {
  token: string;
  user: UserPayload;
}

export interface MeResponseData {
  user: UserPayload;
  organizations: OrganizationMembershipSummary[];
}

// ---------------------------------------------------------------------------
// Client Marketplace (ClientAuthController / PublicMarketplaceController)
// ---------------------------------------------------------------------------

/** A marketplace client's own account — distinct from UserPayload (org staff). */
export interface ClientUserPayload {
  id: number | string;
  name: string;
  email: string;
  phone: string | null;
  status: string;
}

export interface ClientLoginResponseData {
  token: string;
  client: ClientUserPayload;
}

export interface ClientMeResponseData {
  client: ClientUserPayload;
}

/** GET /public/marketplace/organizations — browse-grid card shape. */
export interface PublicOrganizationSummary {
  id: number | string;
  name: string;
  logo_url: string | null;
  description: string | null;
  services_offered: string[];
  service_area: string | null;
  cover_media_id: number | string | null;
}

/** GET /public/marketplace/organizations/{id} — full profile shape. */
export interface PublicOrganizationDetail {
  id: number | string;
  name: string;
  logo_url: string | null;
  currency: string;
  description: string | null;
  services_offered: string[];
  service_area: string | null;
  portfolio: Array<{ id: number | string; file_name: string; mime_type: string }>;
}

/** GET/PATCH /organizations/{id}/profile — staff-side settings shape. */
export interface OrganizationProfileData {
  description: string | null;
  services_offered: string[];
  service_area: string | null;
  is_marketplace_listed: boolean;
}

// ---------------------------------------------------------------------------
// Client Marketplace Chat (ClientConversationController / OrganizationInquiryController)
// ---------------------------------------------------------------------------

export interface ChatMessage {
  id: number | string;
  sender_type: "client" | "org_member";
  body: string;
  created_at: string;
}

/** A client's own conversation list item — GET /client/conversations. */
export interface ClientConversationSummary {
  id: number | string;
  organization: { id: number | string; name: string; logo_url: string | null };
  status: string;
  last_message: ChatMessage | null;
  updated_at: string;
}

/** Staff inbox list item — GET /inquiries. */
export interface InquirySummary {
  id: number | string;
  client: { id: number | string; name: string; email: string };
  status: string;
  last_message: ChatMessage | null;
  updated_at: string;
}

/** GET /inquiries/{conversation} — adds the full message history. */
export interface InquiryDetail extends InquirySummary {
  messages: ChatMessage[];
}

/** POST /inquiries/{conversation}/create-client — see ClientUserLinkingService's docblock. */
export interface InquiryClientHandoff {
  client: { id: number | string; name: string; email: string; phone: string | null } | null;
  candidates: Array<{ id: number | string; name: string; email: string; phone: string | null }> | null;
}

// ---------------------------------------------------------------------------
// Client Marketplace Dashboard (ClientProjectController / ClientDealController)
// ---------------------------------------------------------------------------

/** GET /client/projects list item. */
export interface ClientProjectSummary {
  id: number | string;
  code: string;
  name: string;
  status: string;
}

/** GET /client/projects/{id} — same client-safe shape as PublicClientPortalController::overview(). */
export interface ClientProjectOverview {
  project: {
    code: string;
    name: string;
    status: string;
    start_date: string | null;
    target_end_date: string | null;
  };
  organization: { currency: string };
  financials: { value: number | string; collected: number | string; outstanding: number | string };
  counts: { proposals: number; change_orders: number; payments: number };
}

/** GET /client/deals list item — deliberately no created_by (staff-internal field). */
export interface ClientDealSummary {
  id: number | string;
  project_id: number | string;
  version_no: number;
  status: string;
  sent_at: string | null;
  approved_at: string | null;
  grand_total: number | string;
}

// ---------------------------------------------------------------------------
// Clients (ClientController / ClientResource)
// ---------------------------------------------------------------------------

export interface ClientSummary {
  id: number | string;
  name: string;
  phone: string | null;
}

export interface Client {
  id: number | string;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  notes: string | null;
  properties_count?: number;
  projects_count?: number;
  /** Only present on GET /clients/{id} (eager-loaded there, absent on the list endpoint). */
  properties?: Property[];
  /** Only present on GET /clients/{id}. */
  projects?: ProjectSummary[];
  created_at: ISODateString;
  updated_at: ISODateString;
}

export interface ClientFormInput {
  name: string;
  phone?: string;
  email?: string;
  address?: string;
  notes?: string;
}

// ---------------------------------------------------------------------------
// Properties (PropertyController / PropertyResource)
// ---------------------------------------------------------------------------

export interface PropertySummary {
  id: number | string;
  type: string | null;
  compound: string | null;
  address: string | null;
}

export interface Property {
  id: number | string;
  client_id: number | string;
  client?: ClientSummary;
  type: string | null;
  compound: string | null;
  address: string | null;
  area_m2: number | string | null;
  bedrooms: number | null;
  bathrooms: number | null;
  metadata_json: Record<string, unknown> | null;
  projects_count?: number;
  /** Only present on GET /properties/{id} (eager-loaded there). */
  projects?: ProjectSummary[];
  created_at: ISODateString;
  updated_at: ISODateString;
}

export interface PropertyFormInput {
  type: string;
  compound?: string;
  address?: string;
  area_m2?: number;
  bedrooms?: number;
  bathrooms?: number;
  metadata_json?: Record<string, unknown>;
}

// ---------------------------------------------------------------------------
// Projects (ProjectController / ProjectResource)
// ---------------------------------------------------------------------------

export interface ProjectSummary {
  id: number | string;
  code: string | null;
  name: string;
  status: ProjectStatus;
}

export type ProjectStatus =
  | "draft"
  | "active"
  | "on_hold"
  | "completed"
  | "cancelled"
  | string;

export interface ProjectFinancials {
  /** Contract/proposal commercial value. 0 until Sprint 4/5 populate it. */
  value: number;
  /** Sum of recorded payments. 0 until Sprint 6. */
  collected: number;
  /** value - collected. 0 until Sprint 6. */
  outstanding: number;
  /** Sum of every non-cancelled PO line's quoted total (BRD §8/§9 — ProjectCostCalculator). */
  quoted_cost: number | string;
  /** Same, restricted to POs actually sent to a supplier (not still draft). */
  committed_cost: number | string;
  /** Supplier purchases actually received + recorded Expenses. Real data since Procurement/
   *  Expenses were built (BRD) — previously a hardcoded 0 placeholder. */
  actual_cost: number | string;
  /** contract_value - actual_cost. null (not 0) until a contract exists — no revenue basis yet. */
  gross_profit: number | string | null;
  /** gross_profit / contract_value * 100. null when gross_profit is null or contract_value is 0. */
  margin_percent: number | string | null;
}

export interface ProjectMember {
  id: number | string;
  role: string;
  user: UserSummary;
}

export interface ProjectService {
  id: number | string;
  service_type: string;
  pricing_method: string;
  price: number | string;
  metadata_json: Record<string, unknown> | null;
}

export interface Project {
  id: number | string;
  code: string | null;
  name: string;
  status: ProjectStatus;
  client?: ClientSummary;
  property?: PropertySummary | null;
  responsible_user?: UserSummary | null;
  start_date: ISODateString | null;
  target_end_date: ISODateString | null;
  members?: ProjectMember[];
  services?: ProjectService[];
  financials: ProjectFinancials;
  created_at: ISODateString;
  updated_at: ISODateString;
}

export interface ProjectFormInput {
  client_id: number | string;
  property_id?: number | string;
  name: string;
  code?: string;
  start_date?: string;
  target_end_date?: string;
}

// ---------------------------------------------------------------------------
// BOQ (Sprint 2 — BoqController/BoqCategoryController/BoqItemController/BoqTreeService).
// Money fields arrive as decimal strings (e.g. "150.00") per the backend's decimal columns —
// same convention as Property.area_m2. Route all display through formatEGP/formatTrimmedNumber,
// never render these raw.
// ---------------------------------------------------------------------------

export interface BoqSubtotal {
  direct_cost: string;
  client_total: string;
}

export interface BoqItem {
  id: number | string;
  project_id: number | string;
  category_id: number | string;
  room_id: number | string | null;
  name: string;
  description: string | null;
  quantity: number | string;
  unit: string;
  material_unit_cost: number | string;
  labor_unit_cost: number | string;
  other_unit_cost: number | string;
  client_unit_price: number | string;
  direct_cost: number | string;
  client_total: number | string;
  supplier_id: number | string | null;
  notes: string | null;
  sort_order: number;
  archived_at: ISODateString | null;
  created_at: ISODateString;
  updated_at: ISODateString;
  source_template_id: number | string | null;
  source_template_version_id: number | string | null;
  source_catalog_item_id: number | string | null;
  source_template?: { id: number | string; name: string } | null;
  source_catalog_item?: { id: number | string; name: string } | null;
}

export interface BoqItemFormInput {
  category_id: number | string;
  room_id?: number | string | null;
  name: string;
  description?: string | null;
  quantity: number | string;
  unit: string;
  material_unit_cost?: number | string;
  labor_unit_cost?: number | string;
  other_unit_cost?: number | string;
  client_unit_price?: number | string;
  supplier_id?: number | string | null;
  notes?: string | null;
  sort_order?: number;
}

/** Nested category node as returned inside GET /projects/{id}/boq's `categories[]` tree. */
export interface BoqCategoryNode {
  id: number | string;
  project_id: number | string;
  parent_id: number | string | null;
  name: string;
  sort_order: number;
  subtotal: BoqSubtotal;
  items: BoqItem[];
  children: BoqCategoryNode[];
}

export interface BoqCategoryFormInput {
  name: string;
  parent_id?: number | string | null;
  sort_order?: number;
}

export interface BoqRoom {
  id: number | string;
  project_id?: number | string;
  name: string;
  area_m2: number | string | null;
  sort_order: number;
  /** Present on rooms nested in GET /projects/{id}/boq's tree; absent from the plain
   *  GET/POST /projects/{id}/rooms responses — room subtotals are always computed
   *  client-side from `items` anyway (see computeSubtotal in boq/page.tsx), so callers
   *  should never need to read this field. */
  subtotal?: BoqSubtotal;
  created_at?: ISODateString;
  updated_at?: ISODateString;
}

/** POST /projects/{id}/rooms body. */
export interface BoqRoomFormInput {
  name: string;
  area_m2?: number | string | null;
  sort_order?: number;
}

/** GET /projects/{id}/boq — the full project BOQ tree. */
export interface BoqTree {
  categories: BoqCategoryNode[];
  rooms: BoqRoom[];
  grand_total: BoqSubtotal;
  include_archived: boolean;
}

/** POST /projects/{id}/boq/categories' plain (non-nested) response shape. */
export interface BoqCategory {
  id: number | string;
  project_id: number | string;
  parent_id: number | string | null;
  name: string;
  sort_order: number;
  created_at: ISODateString;
  updated_at: ISODateString;
}

// ---------------------------------------------------------------------------
// BOQ Master Catalog + Standard Templates. Mirrors the backend's three-layer model:
// Master Catalog (BoqCatalogCategory/BoqCatalogItem, "what an item is") -> Templates
// (BoqTemplate/BoqTemplateVersion/BoqTemplateItem, "what normally goes together") -> Project
// BOQ (BoqItem, source-tracked via source_template_id etc. above). A nullable organization_id
// (surfaced here as `is_system`) means global/system data visible to every organization,
// matching OrganizationScope's "organization_id = current OR NULL" convention everywhere else
// in this app. Cost fields are non-binding starting suggestions only — the Project BOQ item the
// engineer commits (and can edit) remains the sole financial source of truth.
// ---------------------------------------------------------------------------

export interface BoqUnit {
  id: number | string;
  code: string;
  name_en: string;
  name_ar: string;
}

export interface BoqCatalogItemSummary {
  id: number | string;
  category_id: number | string;
  organization_id: number | string | null;
  is_system: boolean;
  name: string;
  name_en: string | null;
  name_ar: string | null;
  description: string | null;
  description_en: string | null;
  description_ar: string | null;
  default_material_unit_cost: number | string | null;
  default_labor_unit_cost: number | string | null;
  default_other_unit_cost: number | string | null;
  default_client_unit_price: number | string | null;
  is_active: boolean;
}

/** Nested catalog category node as returned inside GET /boq-catalog/categories. */
export interface BoqCatalogCategoryNode {
  id: number | string;
  organization_id: number | string | null;
  is_system: boolean;
  parent_id: number | string | null;
  name: string;
  name_en: string | null;
  name_ar: string | null;
  sort_order: number;
  items: BoqCatalogItemSummary[];
  children: BoqCatalogCategoryNode[];
}

/** GET /boq-catalog/categories (and its /platform mount). */
export interface BoqCatalogTree {
  categories: BoqCatalogCategoryNode[];
}

export const BOQ_TEMPLATE_TYPES = [
  "FULL_FINISHING",
  "RENOVATION",
  "PARTIAL_FINISHING",
  "ROOM",
  "TRADE",
  "PACKAGE",
  "PREMIUM",
  "LUXURY",
  "CUSTOM",
] as const;
export type BoqTemplateType = (typeof BOQ_TEMPLATE_TYPES)[number];

export type BoqTemplateFinishingLevel = "BASIC" | "STANDARD" | "PREMIUM" | "LUXURY";

export interface BoqTemplateVersionSummary {
  id: number | string;
  version_number: number;
  status: "draft" | "published" | "archived";
  published_at: ISODateString | null;
}

/** A template header — GET /boq-templates and /boq-templates/{id} (and their /platform mounts). */
export interface BoqTemplate {
  id: number | string;
  organization_id: number | string | null;
  is_system: boolean;
  code: string;
  name: string;
  name_en: string | null;
  name_ar: string | null;
  description: string | null;
  description_en: string | null;
  description_ar: string | null;
  template_type: BoqTemplateType;
  project_type: string | null;
  finishing_level: BoqTemplateFinishingLevel | null;
  is_active: boolean;
  active_version?: BoqTemplateVersionSummary | null;
  versions_count?: number;
  applications_count?: number;
}

export interface BoqTemplateFormInput {
  code: string;
  name: string;
  name_en?: string | null;
  name_ar?: string | null;
  description?: string | null;
  template_type: BoqTemplateType;
  project_type?: string | null;
  finishing_level?: BoqTemplateFinishingLevel | null;
}

/** A template line item — GET /boq-templates/{id}/versions/{versionId}. */
export interface BoqTemplateItem {
  id: number | string;
  template_version_id: number | string;
  category_id: number | string;
  catalog_item?: BoqCatalogItemSummary;
  default_unit?: BoqUnit | null;
  default_quantity: number | string | null;
  quantity_formula: string | null;
  quantity_source: "FIXED_DEFAULT" | "FORMULA" | "USER_INPUT" | "OPTIONAL";
  is_required: boolean;
  is_optional: boolean;
  is_enabled_by_default: boolean;
  material_unit_cost: number | string | null;
  labor_unit_cost: number | string | null;
  other_unit_cost: number | string | null;
  client_unit_price: number | string | null;
  notes: string | null;
  sort_order: number;
}

export interface BoqTemplateVersionItemFormInput {
  catalog_item_id: number | string;
  category_id?: number | string;
  default_unit_id?: number | string | null;
  default_quantity?: number | string | null;
  is_required?: boolean;
  is_optional?: boolean;
  material_unit_cost?: number | string | null;
  labor_unit_cost?: number | string | null;
  other_unit_cost?: number | string | null;
  client_unit_price?: number | string | null;
  notes?: string | null;
  sort_order?: number;
}

/** GET /boq-templates/{id}/versions/{versionId}. */
export interface BoqTemplateVersion {
  id: number | string;
  template_id: number | string;
  version_number: number;
  status: "draft" | "published" | "archived";
  published_at: ISODateString | null;
  change_notes: string | null;
  items_count?: number;
  items?: BoqTemplateItem[];
}

export interface BoqTemplateUsage {
  applications_count: number;
  recent_applications: Array<{
    id: number | string;
    project: { id: number | string; name: string } | null;
    item_count: number;
    created_at: ISODateString;
  }>;
}

/** One entry of POST /projects/{id}/boq/template-preview's `selections[]`. */
export interface BoqTemplatePreviewSelection {
  template_version_id: number | string;
  selected_optional_item_ids?: Array<number | string>;
}

/** A merged line item inside the preview tree — the fields the apply dialog lets the engineer review/edit before commit. */
export interface BoqTemplatePreviewItem {
  catalog_item_id: number | string;
  name: string;
  name_en: string | null;
  name_ar: string | null;
  default_unit: BoqUnit | null;
  quantity: number | string | null;
  quantity_source: string;
  is_required: boolean;
  suggested_material_unit_cost: number | string | null;
  suggested_labor_unit_cost: number | string | null;
  suggested_other_unit_cost: number | string | null;
  suggested_client_unit_price: number | string | null;
  needs_review: boolean;
  contributed_by: Array<{
    template_id: number | string;
    template_name: string;
    template_version_id: number | string;
    template_item_id: number | string;
    default_quantity: number | string | null;
  }>;
}

export interface BoqTemplatePreviewCategoryNode {
  id: number | string;
  parent_id: number | string | null;
  name: string;
  name_en: string | null;
  name_ar: string | null;
  items: BoqTemplatePreviewItem[];
  children: BoqTemplatePreviewCategoryNode[];
}

/** POST /projects/{id}/boq/template-preview — a pure read, never writes. */
export interface BoqTemplatePreview {
  categories: BoqTemplatePreviewCategoryNode[];
}

/** One resolved line POST /projects/{id}/boq/template-commit's `items[]` expects. */
export interface BoqTemplateCommitItem {
  catalog_item_id: number | string;
  category_id?: number | string;
  name?: string;
  unit?: string;
  quantity: number | string;
  material_unit_cost?: number | string | null;
  labor_unit_cost?: number | string | null;
  other_unit_cost?: number | string | null;
  client_unit_price?: number | string | null;
  source_template_id?: number | string | null;
  source_template_version_id?: number | string | null;
  source_template_item_id?: number | string | null;
}

/** POST /projects/{id}/boq/import. */
export interface BoqImportResult {
  created: number;
  skipped: number;
  errors: Array<{ row: number; reason: string }>;
}

// ---------------------------------------------------------------------------
// Pricing (Sprint 3 — S08 Pricing Panel). `pricing_rules` are project-level layers applied on
// top of the BOQ's summed line-item pricing (see docs/PROJECT_CONTEXT.md Sprint 3 scope) —
// distinct from Sprint 2's per-item client_unit_price. Money/decimal fields arrive as strings
// from the backend's bcmath-based PricingCalculator, same convention as BoqItem — always format
// via formatEGP, never render raw.
// ---------------------------------------------------------------------------

export type PricingRuleType = "markup" | "fee" | "discount";
export type PricingMethod = "percentage" | "fixed_amount";
export type PricingBaseSelector = "boq_direct_cost" | "boq_client_subtotal" | "running_subtotal";

/** GET/POST /projects/{id}/pricing/rules, PATCH /pricing/rules/{id} item shape. */
export interface PricingRule {
  id: number | string;
  project_id: number | string;
  name: string;
  type: PricingRuleType;
  method: PricingMethod;
  value: number | string;
  base_selector: PricingBaseSelector;
  sort_order: number;
  active: boolean;
  created_at: ISODateString;
  updated_at: ISODateString;
}

export interface PricingRuleFormInput {
  name: string;
  type: PricingRuleType;
  method: PricingMethod;
  value: number | string;
  base_selector: PricingBaseSelector;
  sort_order?: number;
  active?: boolean;
}

/** One step of the ordered rule breakdown inside GET .../pricing/breakdown and the recalculate
 *  response — see PricingCalculator's docblock for the unsigned-magnitude sign convention. */
export interface PricingBreakdownRule {
  id: number | string;
  name: string;
  type: PricingRuleType;
  method: PricingMethod;
  value: number | string;
  base_selector: PricingBaseSelector;
  base_amount_used: number | string;
  computed_amount: number | string;
  running_subtotal_after: number | string;
}

/**
 * GET /projects/{id}/pricing/breakdown and POST /projects/{id}/pricing/recalculate response
 * shape. When `priced` is false (project never recalculated) every total is null and `rules` is
 * empty — render an explicit "recalculate to see pricing" prompt, never zeros, per
 * PricingController's docblock.
 */
export interface PricingBreakdown {
  priced: boolean;
  direct_cost_total: number | string | null;
  client_subtotal: number | string | null;
  rules: PricingBreakdownRule[];
  markup_total: number | string | null;
  fees_total: number | string | null;
  discount_total: number | string | null;
  grand_total: number | string | null;
  priced_at: ISODateString | null;
}

// ---------------------------------------------------------------------------
// Proposals (Sprint 4 — S09 Proposal Editor / S10 Version History). `content_json` is a
// flexible JSONB blob on the backend (no fixed columns) — the frontend picks a fixed set of
// keys and uses them consistently between what it saves (PATCH/POST) and what it displays.
// Money/decimal fields arrive as strings, same convention as BoqItem/PricingRule — always
// format via formatEGP, never render raw. `snapshot_json` is intentionally left loosely typed
// (Record<string, unknown>) — it's an internal frozen record we don't need to render field-by-
// field (the flat `items`/totals columns already expose the client-shaped view we display).
// ---------------------------------------------------------------------------

export type ProposalStatus = "draft" | "sent" | "approved" | "changes_requested";

/** The fixed set of editorial fields this frontend reads/writes inside `content_json`. The
 *  backend stores this as an arbitrary JSON blob, so any key is technically legal — but every
 *  screen that authors or displays proposal content must stick to exactly this shape or saved
 *  content silently stops round-tripping through the UI. All fields optional/nullable since a
 *  freshly created draft may have an empty or partially-filled content_json (or even `null`,
 *  confirmed live: POST with no content_json body returns `content_json: null`). */
export interface ProposalContent {
  cover?: string | null;
  scope?: string | null;
  exclusions?: string | null;
  timeline?: string | null;
  terms?: string | null;
  payment_plan?: string | null;
}

/** GET .../proposals list item shape (S10 version history rows). */
export interface ProposalVersionSummary {
  id: number | string;
  version_no: number;
  status: ProposalStatus;
  grand_total: number | string;
  created_by: UserSummary;
  sent_at: ISODateString | null;
  approved_at: ISODateString | null;
}

/** Frozen client-shaped line item copied from a boq_item at proposal-version-creation time —
 *  never re-read from boq_items afterward. No cost/margin fields, matches BoqItem's client-
 *  facing split (see PROJECT_CONTEXT.md Sprint 4 schema notes). */
export interface ProposalItem {
  id: number | string;
  source_boq_item_id: number | string | null;
  description: string;
  quantity: number | string;
  unit: string;
  unit_price: number | string;
  line_total: number | string;
}

/** GET /proposals/{id} and POST /projects/{id}/proposals response shape (full detail). */
export interface ProposalVersion {
  id: number | string;
  project_id: number | string;
  version_no: number;
  status: ProposalStatus;
  content_json: ProposalContent | null;
  /** Full frozen snapshot (project/client/org/content/pricing/items at send time). Only
   *  populated once the version is sent — `null` while still draft. Not rendered field-by-field
   *  by this frontend; the flat `items`/totals fields above already give us what we display. */
  snapshot_json: Record<string, unknown> | null;
  subtotal: number | string;
  markup_total: number | string;
  fees_total: number | string;
  discount_total: number | string;
  grand_total: number | string;
  items: ProposalItem[];
  created_by: UserSummary;
  sent_at: ISODateString | null;
  approved_at: ISODateString | null;
  created_at: ISODateString;
  updated_at: ISODateString;
}

/** POST /projects/{id}/proposals request body. */
export interface ProposalCreateInput {
  content_json?: ProposalContent;
}

/** PATCH /proposals/{id} request body — only valid while status is 'draft', 409 otherwise. */
export interface ProposalUpdateInput {
  content_json: ProposalContent;
}

/** POST /proposals/{id}/send response — the one-time-only surface for the public link + OTP;
 *  the OTP is stored hashed server-side and is never returned again by any other endpoint. */
export interface ProposalSendResult {
  id: number | string;
  version_no: number;
  status: ProposalStatus;
  sent_at: ISODateString;
  public_url: string;
  otp_code: string;
}

// ---------------------------------------------------------------------------
// Contracts (Sprint 5 — S12 Contract). Creation IS the signing act (no separate e-signature
// step — see docs/PROJECT_CONTEXT.md Sprint 5's "what contract signing means for MVP"):
// signed_at is set the moment POST .../from-proposal/{id} succeeds. contract_value and the
// linked proposal_version are permanently locked at creation (copied from the source proposal's
// grand_total, verified live to match exactly) — only start_date/end_date/terms_json are ever
// editable via PATCH. `terms_json` is a flexible JSONB blob like proposals' `content_json`; this
// frontend fixes on the same six-key subset shape convention.
// ---------------------------------------------------------------------------

export type ContractStatus = "active" | string;

/** The fixed set of fields this frontend reads/writes inside `terms_json` — seeded server-side
 *  from the source proposal's content_json at creation, then stored as the contract's own
 *  independent copy (editing it never touches the original proposal). All optional/nullable,
 *  same convention as ProposalContent. */
export interface ContractTerms {
  terms?: string | null;
  exclusions?: string | null;
  timeline?: string | null;
  payment_plan?: string | null;
  warranty_period?: string | null;
  cancellation_policy?: string | null;
}

/** GET /contracts/{id} and POST .../contracts/from-proposal/{proposalId} response shape
 *  (verified live — both use the same ContractResource with project.client/proposalVersion
 *  eager-loaded). `project`/`client` are minimal summaries, not full resources. */
export interface Contract {
  id: number | string;
  project_id: number | string;
  contract_no: string;
  status: ContractStatus;
  contract_value: number | string;
  signed_at: ISODateString;
  start_date: ISODateString | null;
  end_date: ISODateString | null;
  terms_json: ContractTerms | null;
  project: ProjectSummary | null;
  client: ClientSummary | null;
  proposal_version: { id: number | string; version_no: number } | null;
  created_at: ISODateString;
  updated_at: ISODateString;
}

/** PATCH /contracts/{id} request body. Deliberately has no contract_value/proposal_version_id
 *  fields at all — the backend rejects either with 422 `prohibited` if sent (verified live), so
 *  this type never gives a caller the option to try. */
export interface ContractUpdateInput {
  start_date?: string | null;
  end_date?: string | null;
  terms_json?: ContractTerms;
}

// ---------------------------------------------------------------------------
// Payments (Sprint 6 — S13 Payments). `payment_schedules` only ever stores two statuses,
// `pending` and `paid` — "overdue"/"upcoming" are NOT separate stored states, they're computed
// at read time from `pending AND due_date` vs today (see docs/PROJECT_CONTEXT.md Sprint 6 schema
// notes) and exposed here as the three booleans below so the frontend never needs to re-derive
// the date comparison itself (timezone/off-by-one risk) — always filter/badge off
// is_overdue/is_upcoming/is_paid, never off `status` directly. Money/decimal fields arrive as
// strings from the backend's bcmath-based calculations, same convention as BoqItem/Contract —
// always format via formatEGP, never render raw.
// ---------------------------------------------------------------------------

export type PaymentScheduleStatus = "pending" | "paid";

/** GET /contracts/{id}/payment-schedules and POST .../payment-schedules response shape. */
export interface PaymentSchedule {
  id: number | string;
  contract_id: number | string;
  name: string;
  sequence_no: number;
  due_date: ISODateString;
  /** Only set if the schedule was created via the percentage input mode; null if created via a
   *  direct fixed amount. Purely informational — `amount` is always the authoritative stored
   *  value either way (computed server-side via bcmath when percentage is given). */
  percentage: number | string | null;
  amount: number | string;
  status: PaymentScheduleStatus;
  is_overdue: boolean;
  is_upcoming: boolean;
  is_paid: boolean;
  created_at: ISODateString;
  updated_at: ISODateString;
}

/** POST /contracts/{id}/payment-schedules body. Exactly one of `percentage`/`amount` should be
 *  sent per the "percentage of contract value vs fixed amount" toggle in the UI — the backend
 *  requires at least one (422 otherwise) and computes `amount` from `percentage * contract_value`
 *  via bcmath when percentage is given (see StorePaymentScheduleRequest's docblock). */
export interface PaymentScheduleFormInput {
  name: string;
  sequence_no: number;
  due_date: string;
  percentage?: number | string;
  amount?: number | string;
}

/** The three payment methods surfaced in the Record Payment form's select. The backend stores
 *  this as a free-form string (no DB enum, matching the codebase's established convention), so
 *  this union is a frontend-only convenience, not a hard backend constraint. */
export type PaymentMethod = "bank_transfer" | "cash" | "cheque";

/** GET /payment-schedules/{id}/payments and POST .../payments response shape. Deliberately has
 *  no `receipt_url` field — only the `has_receipt` boolean — per the backend's discipline of
 *  never exposing the raw local-disk storage path; fetch the file itself via the dedicated
 *  GET /payments/{id}/receipt route (see downloadPaymentReceipt in resources/payments.ts). */
export interface Payment {
  id: number | string;
  project_id: number | string;
  payment_schedule_id: number | string;
  amount: number | string;
  payment_method: string;
  paid_at: ISODateString;
  reference: string | null;
  has_receipt: boolean;
  notes: string | null;
  created_at: ISODateString;
  updated_at: ISODateString;
}

/** POST /payment-schedules/{id}/payments body — sent as multipart/form-data (not JSON) because
 *  of the optional `receipt` file field, see resources/payments.ts's recordPayment(). */
export interface PaymentFormInput {
  amount: number | string;
  payment_method: PaymentMethod | string;
  paid_at: string;
  reference?: string;
  notes?: string;
  receipt?: File | null;
}

// ---------------------------------------------------------------------------
// Change Orders (Sprint 7 — S14 Change Orders). Four-verb lifecycle: draft -> sent ->
// approved|rejected -> applied (see docs/PROJECT_CONTEXT.md Sprint 7 scope). `apply` is a
// separate staff-triggered step from client `approve` — approving never touches the BOQ/
// contract by itself. Money/decimal fields (price_delta, unit prices) and quantity arrive as
// decimal strings from the backend, same convention as BoqItem/ProposalVersion — always format
// via formatEGP, never render raw. Draft-only editability mirrors proposals' immutability rule
// exactly: PATCH only succeeds while status === 'draft' (409 CHANGE_ORDER_NOT_EDITABLE
// otherwise) — see ChangeOrderController's docblock (verified against backend source).
// ---------------------------------------------------------------------------

export type ChangeOrderStatus = "draft" | "sent" | "approved" | "rejected" | "applied";

export type ChangeOrderItemAction = "add" | "remove" | "modify";

/** GET /change-orders/{id} item shape (internal — includes boq_item_id, never exposed on the
 *  public/token-authenticated surface). line_delta is always computed server-side:
 *    add    -> quantity * new_unit_price (positive)
 *    remove -> -(quantity * old_unit_price) (negative)
 *    modify -> quantity * (new_unit_price - old_unit_price) (sign follows price direction)
 *  (verified against ChangeOrderService::computeLineDelta()). */
export interface ChangeOrderItem {
  id: number | string;
  action: ChangeOrderItemAction;
  boq_item_id: number | string | null;
  description: string;
  quantity: number | string;
  unit: string;
  old_unit_price: number | string | null;
  new_unit_price: number | string | null;
  line_delta: number | string;
}

/** One item row in the POST/PATCH request body. Per StoreChangeOrderRequest/
 *  UpdateChangeOrderRequest (verified against backend source):
 *   - 'add': boq_item_id and old_unit_price must be omitted; new_unit_price required.
 *   - 'remove': boq_item_id required; new_unit_price must be omitted; old_unit_price optional
 *     (falls back server-side to the referenced boq_item's current client_unit_price).
 *   - 'modify': boq_item_id required; both old_unit_price (optional, same fallback) and
 *     new_unit_price (required) apply.
 *  description/quantity/unit are required for every action. */
export interface ChangeOrderItemInput {
  action: ChangeOrderItemAction;
  boq_item_id?: number | string;
  description: string;
  quantity: number | string;
  unit: string;
  old_unit_price?: number | string;
  new_unit_price?: number | string;
}

/** GET /projects/{id}/change-orders list item shape (ChangeOrderSummaryResource — verified
 *  against backend source). Deliberately excludes reason/items, only in the full detail view. */
export interface ChangeOrderSummary {
  id: number | string;
  number: string;
  status: ChangeOrderStatus;
  price_delta: number | string;
  timeline_delta_days: number | null;
  sent_at: ISODateString | null;
  approved_at: ISODateString | null;
  applied_at: ISODateString | null;
}

/** GET /change-orders/{id} and POST /projects/{id}/change-orders response shape
 *  (ChangeOrderResource — verified against backend source). */
export interface ChangeOrder {
  id: number | string;
  project_id: number | string;
  number: string;
  status: ChangeOrderStatus;
  reason: string;
  price_delta: number | string;
  timeline_delta_days: number | null;
  items: ChangeOrderItem[];
  requested_by: UserSummary | null;
  sent_at: ISODateString | null;
  approved_at: ISODateString | null;
  applied_at: ISODateString | null;
  created_at: ISODateString;
  updated_at: ISODateString;
}

/** POST /projects/{id}/change-orders request body. project_id/number/status/price_delta are
 *  never accepted — price_delta is always computed server-side from items' line_delta. */
export interface ChangeOrderCreateInput {
  reason: string;
  timeline_delta_days?: number | null;
  items: ChangeOrderItemInput[];
}

/** PATCH /change-orders/{id} request body — only valid while status is 'draft', 409
 *  CHANGE_ORDER_NOT_EDITABLE otherwise. `items`, when present, wholesale-replaces the entire
 *  item set (delete-then-rebuild server-side) — see UpdateChangeOrderRequest's docblock. */
export interface ChangeOrderUpdateInput {
  reason?: string;
  timeline_delta_days?: number | null;
  items?: ChangeOrderItemInput[];
}

/** POST /change-orders/{id}/send response — the one-time-only surface for the public link +
 *  OTP, same pattern as ProposalSendResult (the OTP is stored hashed server-side and never
 *  returned again by any other endpoint). */
export interface ChangeOrderSendResult {
  id: number | string;
  number: string;
  status: ChangeOrderStatus;
  sent_at: ISODateString;
  public_url: string;
  otp_code: string;
}

// ---------------------------------------------------------------------------
// Pagination envelope (Laravel's default paginate() JSON shape)
// ---------------------------------------------------------------------------

export interface PaginationLinks {
  first: string | null;
  last: string | null;
  prev: string | null;
  next: string | null;
}

export interface PaginationMeta {
  current_page: number;
  from: number | null;
  last_page: number;
  path: string;
  per_page: number;
  to: number | null;
  total: number;
}

export interface Paginated<T> {
  data: T[];
  links: PaginationLinks;
  meta: PaginationMeta;
}

// ---------------------------------------------------------------------------
// Audit Log (AuditLogController — Platform Readiness Review finding #05 "Audit log viewer").
// Mirrors AuditLogResource exactly. `before`/`after` are the raw changed-field snapshots
// App\Models\Concerns\Auditable records — already scrubbed of password/hidden fields
// server-side, shown verbatim here since this is a MANAGE_ORGANIZATION-gated admin surface.
// ---------------------------------------------------------------------------

export interface AuditLogEntry {
  id: number | string;
  actor: { id: number | string; name: string } | null;
  entity_type: string;
  entity_id: number | string;
  action: string;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  ip_address: string | null;
  created_at: ISODateString;
}

// ---------------------------------------------------------------------------
// Reports (ReportController — PROJECT_CONTEXT.md Sprint 8 "Reports" / S21). Both endpoints
// require Permissions::VIEW_FINANCIALS. `estimated_margin` is derived from BOQ pricing
// (grand_total - direct_cost_total), never real expenses — label it "estimated" everywhere in
// the UI, never "profit", per PROJECT_CONTEXT.md's explicit instruction.
// ---------------------------------------------------------------------------

export interface ReportSummary {
  total_revenue: number | string;
  total_receivables: number | string;
  estimated_margin: number | string;
  total_change_order_value: number | string;
  monthly_revenue: number | string;
  pending_proposals_count: number;
  project_count: number;
  active_project_count: number;
}

export interface ReportProjectRow {
  id?: number | string;
  name: string;
  code: string | null;
  status: ProjectStatus;
  contract_value: number | string;
  collected: number | string;
  outstanding: number | string;
  estimated_margin: number | string;
  change_order_value: number | string;
  /** contract_value - the originating proposal's frozen grand_total. Equals change_order_value
   *  exactly by construction (see ReportController docblock) — a good internal consistency
   *  check, not just a display figure. */
  budget_variance: number | string;
}

// ---------------------------------------------------------------------------
// Notifications (NotificationController — PROJECT_CONTEXT.md Sprint 8 "Notifications").
// GET /notifications is the current user's own, paginated, newest-first. Live-verified shape:
// the envelope key is `payload` (already-decoded JSON, not a raw `payload_json` string) and
// includes a `created_at` alongside `sent_at`/`read_at`.
// ---------------------------------------------------------------------------

export type NotificationType =
  | "proposal_approved"
  | "proposal_changes_requested"
  | "contract_created"
  | "payment_received"
  | "change_order_approved"
  | "change_order_rejected"
  | "new_inquiry_message"
  | string;

export interface NotificationPayload {
  /** Human-readable summary — render this when present, per PROJECT_CONTEXT.md's UX note. */
  summary?: string;
  project_id?: number | string;
  project_name?: string;
  /** BRD v4 "Client Marketplace" — only set on `new_inquiry_message` notifications, see
   *  NotificationService::notifyOrganization()'s call site in ClientConversationController. */
  conversation_id?: number | string;
  [key: string]: unknown;
}

export interface Notification {
  id: number | string;
  channel: string;
  type: NotificationType;
  payload: NotificationPayload | null;
  sent_at: ISODateString | null;
  read_at: ISODateString | null;
  created_at: ISODateString;
}

// ---------------------------------------------------------------------------
// Organization profile + members (OrganizationController / OrganizationMemberController —
// PROJECT_CONTEXT.md Sprint 8 "Settings" / S22, scoped down).
// ---------------------------------------------------------------------------

export interface OrganizationProfile {
  id: number | string;
  name: string;
  legal_name: string | null;
  logo_url: string | null;
  phone: string | null;
  email: string | null;
  currency: string | null;
  timezone: string | null;
  created_at: ISODateString;
  updated_at: ISODateString;
}

export interface OrganizationProfileFormInput {
  name?: string;
  legal_name?: string | null;
  logo_url?: string | null;
  phone?: string | null;
  email?: string | null;
  currency?: string | null;
  timezone?: string | null;
}

export interface OrganizationRoleSummary {
  id: number | string;
  name: string;
}

export interface OrganizationMember {
  id: number | string;
  user: UserSummary;
  role: OrganizationRoleSummary | null;
  status: string;
}

// ---------------------------------------------------------------------------
// Project attachments / Documents tab (ProjectMediaController, backed by Spatie Media
// Library). Three fixed collections — must match backend Project::MEDIA_COLLECTIONS exactly.
// No raw disk path is ever exposed; the file itself is only reachable via the dedicated
// GET /media/{id}/file route (see resources/media.ts), same discipline as Payment's
// has_receipt/downloadPaymentReceipt().
// ---------------------------------------------------------------------------

export type ProjectMediaCollection = "designs" | "process" | "final_pictures";

export interface ProjectMedia {
  id: number | string;
  collection: ProjectMediaCollection;
  file_name: string;
  mime_type: string;
  size: number;
  caption: string | null;
  uploaded_by: string | null;
  created_at: ISODateString;
  /** Only present on GET /media (the org-wide gallery) — see GalleryMedia below. */
  project?: { id: number | string; name: string; code: string };
}

/** GET /media — the org-wide "Media" gallery across every project, not just one project's
 *  Documents tab. Same shape as ProjectMedia but `project` is always populated (never
 *  `undefined`) since the gallery is precisely the view where "which project is this from"
 *  matters. */
export interface GalleryMedia extends ProjectMedia {
  project: { id: number | string; name: string; code: string };
}

// ---------------------------------------------------------------------------
// Leads / CRM (LeadController — BRD "CRM/Leads"). `status` never includes "converted" as a
// value the frontend sets directly — that transition only ever happens through
// POST /leads/{id}/convert (see resources/leads.ts's convertLead()), which is also the only
// path that populates converted_client_id/converted_project_id/converted_at.
// ---------------------------------------------------------------------------

export type LeadStatus = "new" | "contacted" | "qualified" | "converted" | "lost";

export interface Lead {
  id: number | string;
  name: string;
  phone: string | null;
  email: string | null;
  source: string | null;
  status: LeadStatus;
  estimated_budget: number | string | null;
  notes: string | null;
  owner: { id: number | string; name: string } | null;
  converted_client_id: number | string | null;
  converted_project_id: number | string | null;
  converted_at: ISODateString | null;
  created_at: ISODateString;
  updated_at: ISODateString;
}

export interface LeadFormInput {
  name: string;
  phone?: string;
  email?: string;
  source?: string;
  estimated_budget?: number | string;
  notes?: string;
}

/** POST /leads/{id}/convert response — always has `client`; `project` is null unless
 *  `create_project: true` was sent. */
export interface LeadConversionResult {
  lead: Lead;
  client: Client;
  project: ProjectSummary | null;
}

// ---------------------------------------------------------------------------
// Procurement & Supplier Intelligence (SupplierController/PurchaseOrderController — BRD
// "Procurement"). PO lifecycle: draft -> sent -> partially_received -> received -> cancelled.
// ---------------------------------------------------------------------------

export interface Supplier {
  id: number | string;
  name: string;
  category: string | null;
  contact_name: string | null;
  phone: string | null;
  email: string | null;
  payment_terms: string | null;
  notes: string | null;
  created_at: ISODateString;
  updated_at: ISODateString;
}

export interface SupplierFormInput {
  name: string;
  category?: string;
  contact_name?: string;
  phone?: string;
  email?: string;
  payment_terms?: string;
  notes?: string;
}

export interface SupplierPriceHistoryEntry {
  id: number | string;
  item_description: string;
  unit: string;
  unit_price: number | string;
  source: "quoted" | "actual";
  recorded_at: ISODateString;
}

export type PurchaseOrderStatus = "draft" | "sent" | "partially_received" | "received" | "cancelled";

export interface PurchaseOrderItem {
  id: number | string;
  description: string;
  unit: string;
  quantity: number | string;
  quoted_unit_price: number | string;
  quoted_total: number | string;
  received_quantity: number | string;
  actual_unit_price: number | string | null;
  actual_total: number | string | null;
}

export interface PurchaseOrder {
  id: number | string;
  project_id: number | string;
  supplier: Supplier;
  po_number: string;
  status: PurchaseOrderStatus;
  notes: string | null;
  sent_at: ISODateString | null;
  items: PurchaseOrderItem[];
  created_at: ISODateString;
  updated_at: ISODateString;
}

export interface PurchaseOrderItemInput {
  description: string;
  unit: string;
  quantity: number | string;
  quoted_unit_price: number | string;
}

export interface PurchaseOrderFormInput {
  supplier_id: number | string;
  po_number?: string;
  notes?: string;
  items: PurchaseOrderItemInput[];
}

export interface ReceivePurchaseOrderLine {
  id: number | string;
  received_quantity: number | string;
  actual_unit_price: number | string;
}

// ---------------------------------------------------------------------------
// Expenses (ExpenseController — BRD "Expenses: project expenses, receipts, supplier linkage").
// ---------------------------------------------------------------------------

export interface Expense {
  id: number | string;
  project_id: number | string;
  supplier: { id: number | string; name: string } | null;
  category: string;
  description: string;
  amount: number | string;
  expense_date: string;
  has_receipt: boolean;
  notes: string | null;
  created_at: ISODateString;
}

export interface ExpenseFormInput {
  supplier_id?: number | string;
  category: string;
  description: string;
  amount: number | string;
  expense_date: string;
  notes?: string;
  receipt?: File | null;
}

// ---------------------------------------------------------------------------
// Execution: Tasks + Site Reports (TaskController/SiteReportController — BRD S16/S17).
// ---------------------------------------------------------------------------

export type TaskStatus = "todo" | "in_progress" | "done";

export interface ExecutionPhoto {
  id: number | string;
  file_name: string;
  mime_type: string;
}

export interface ProjectTask {
  id: number | string;
  project_id: number | string;
  title: string;
  description: string | null;
  assignee: { id: number | string; name: string } | null;
  status: TaskStatus;
  due_date: string | null;
  sort_order: number;
  photos: ExecutionPhoto[];
  created_at: ISODateString;
}

export interface TaskFormInput {
  title: string;
  description?: string;
  assignee_user_id?: number | string;
  status?: TaskStatus;
  due_date?: string;
  photos?: File[];
}

export interface SiteReport {
  id: number | string;
  project_id: number | string;
  reported_by: { id: number | string; name: string };
  report_date: string;
  work_done: string;
  issues: string | null;
  decisions: string | null;
  photos: ExecutionPhoto[];
  created_at: ISODateString;
}

export interface SiteReportFormInput {
  report_date: string;
  work_done: string;
  issues?: string;
  decisions?: string;
  photos?: File[];
}

// ---------------------------------------------------------------------------
// Snagging + Handover (SnagController/HandoverController — BRD S19/S20).
// ---------------------------------------------------------------------------

export type SnagPriority = "low" | "medium" | "high" | "critical";
export type SnagStatus = "open" | "closed";

export interface Snag {
  id: number | string;
  project_id: number | string;
  description: string;
  priority: SnagPriority;
  owner: { id: number | string; name: string } | null;
  due_date: string | null;
  status: SnagStatus;
  is_mandatory: boolean;
  resolution_notes: string | null;
  closed_at: ISODateString | null;
  created_at: ISODateString;
}

export interface SnagFormInput {
  description: string;
  priority?: SnagPriority;
  owner_user_id?: number | string;
  due_date?: string;
  is_mandatory?: boolean;
}

export interface Handover {
  id: number | string;
  project_id: number | string;
  approved_by: { id: number | string; name: string };
  handover_date: string;
  warranty_period_months: number | null;
  warranty_notes: string | null;
  notes: string | null;
  created_at: ISODateString;
}

export interface HandoverFormInput {
  handover_date: string;
  warranty_period_months?: number;
  warranty_notes?: string;
  notes?: string;
}
