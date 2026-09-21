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
  /** Sum of recorded expenses / actual BOQ execution cost. 0 until Sprint 2/3/7. */
  actual_cost: number;
  /** value - actual_cost. null (not 0) until there is a value to subtract from. */
  gross_profit: number | null;
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

/** Nested template category node as returned inside GET /boq-templates/categories. */
export interface BoqTemplateCategoryNode {
  id: number | string;
  organization_id: number | string;
  parent_id: number | string | null;
  name: string;
  sort_order: number;
  items: BoqTemplateItem[];
  children: BoqTemplateCategoryNode[];
}

export interface BoqTemplateItem {
  id: number | string;
  organization_id: number | string;
  category_id: number | string;
  name: string;
  description: string | null;
  unit: string;
  material_unit_cost: number | string;
  labor_unit_cost: number | string;
  other_unit_cost: number | string;
  client_unit_price: number | string;
  notes: string | null;
  sort_order: number;
}

/** GET /boq-templates/categories. */
export interface BoqTemplateTree {
  organization_id: number | string;
  categories: BoqTemplateCategoryNode[];
}

/** POST /projects/{id}/boq/apply-template/{templateCategoryId}. */
export interface ApplyBoqTemplateResult {
  applied_category_id: number | string;
  boq: BoqTree;
}

/** POST /projects/{id}/boq/import. */
export interface BoqImportResult {
  created: number;
  skipped: number;
  errors: Array<{ row: number; reason: string }>;
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
