"use client";

import { useParams } from "next/navigation";
import { BoqTemplateDetailScreen } from "@/components/boqTemplates/BoqTemplateDetailScreen";

export default function OrgBoqTemplateDetailPage() {
  const params = useParams<{ id: string }>();
  return <BoqTemplateDetailScreen platform={false} basePath="/settings/boq-templates" templateId={params.id} />;
}
