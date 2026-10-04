"use client";

import { BoqTemplateListScreen } from "@/components/boqTemplates/BoqTemplateListScreen";

export default function OrgBoqTemplatesPage() {
  return <BoqTemplateListScreen platform={false} basePath="/settings/boq-templates" />;
}
