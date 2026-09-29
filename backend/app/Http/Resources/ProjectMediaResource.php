<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /projects/{project}/media and the response of POST /projects/{project}/media
 * (Documents tab attachments). Deliberately exposes no raw disk path — the file itself is only
 * reachable via GET /media/{media}/file, same access-controlled-indirection discipline as
 * PaymentResource's `has_receipt` vs PaymentController::receipt().
 *
 * The `project` field is only present when the caller eager-loaded Spatie's `model` relation
 * (`Media::model()`, a MorphTo) — see ProjectMediaController::galleryIndex(), the org-wide
 * "Media" gallery, which needs to show which project each file belongs to. The per-project
 * Documents tab endpoint (index()) never loads it, since the project is already implied by the
 * URL there — `whenLoaded` keeps that response unchanged rather than adding a redundant field.
 *
 * @mixin \Spatie\MediaLibrary\MediaCollections\Models\Media
 */
class ProjectMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'collection' => $this->collection_name,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'caption' => $this->getCustomProperty('caption'),
            'uploaded_by' => $this->getCustomProperty('uploaded_by'),
            'created_at' => $this->created_at,
            'project' => $this->whenLoaded('model', fn () => [
                'id' => $this->model->id,
                'name' => $this->model->name,
                'code' => $this->model->code,
            ]),
        ];
    }
}
