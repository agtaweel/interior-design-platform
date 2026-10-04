"use client";

import { BoqTemplateListScreen } from "@/components/boqTemplates/BoqTemplateListScreen";

export default function PlatformBoqTemplatesPage() {
  return <BoqTemplateListScreen platform={true} basePath="/platform/boq-templates" />;
}
