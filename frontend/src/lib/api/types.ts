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
