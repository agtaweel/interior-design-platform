import { apiGetResource } from "@/lib/api/client";
import type { Property } from "@/lib/api/types";

export function getProperty(id: string | number) {
  return apiGetResource<Property>(`/properties/${id}`);
}
