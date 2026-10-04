"use client";

import { useParams } from "next/navigation";
import { BoqTemplateDetailScreen } from "@/components/boqTemplates/BoqTemplateDetailScreen";

export default function PlatformBoqTemplateDetailPage() {
  const params = useParams<{ id: string }>();
  return <BoqTemplateDetailScreen platform={true} basePath="/platform/boq-templates" templateId={params.id} />;
}
