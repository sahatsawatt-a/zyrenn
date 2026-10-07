<?php

namespace App\Http\Controllers\Drive;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Http\Controllers\Controller;
use App\Models\Drive\DriveFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pictures from the photo editor. The browser does the cropping, turning and
 * colour, and sends the picture it made; it is kept as a new file beside the
 * original, which stays as it was -- so a note or board that shows the old
 * one is untouched, and the next edit can start from the whole original.
 */
class PhotoEditController extends Controller
{
    use ActsForOwner;

    /**
     * Keep an edited picture in the Drive of wherever the page is.
     */
    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request, 'contribute');

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.DriveFile::MAX_KB],
            'source' => ['nullable', 'string', 'max:16'],
            'edit' => ['nullable', 'array'],
            'edit.crop' => ['required_with:edit', 'array'],
            'edit.crop.x' => ['required_with:edit', 'numeric', 'between:0,1'],
            'edit.crop.y' => ['required_with:edit', 'numeric', 'between:0,1'],
            'edit.crop.width' => ['required_with:edit', 'numeric', 'between:0,1'],
            'edit.crop.height' => ['required_with:edit', 'numeric', 'between:0,1'],
            'edit.rotate' => ['required_with:edit', 'integer', 'in:0,90,180,270'],
            'edit.flipX' => ['required_with:edit', 'boolean'],
            'edit.flipY' => ['required_with:edit', 'boolean'],
            'edit.brightness' => ['required_with:edit', 'integer', 'between:0,200'],
            'edit.contrast' => ['required_with:edit', 'integer', 'between:0,200'],
            'edit.saturation' => ['required_with:edit', 'integer', 'between:0,200'],
            'edit.warmth' => ['required_with:edit', 'integer', 'between:-100,100'],
            'edit.vignette' => ['required_with:edit', 'integer', 'between:0,100'],
            'edit.angle' => ['required_with:edit', 'numeric', 'between:-45,45'],
            'edit.shape' => ['required_with:edit', 'string', 'in:rect,rounded,circle'],
            'edit.maxSide' => ['required_with:edit', 'integer', 'in:0,1024,1600,2560'],
            // Areas blurred, pixelated or blacked out; a form sends none at all
            // when there are none
            'edit.hidden' => ['nullable', 'array', 'max:50'],
            'edit.hidden.*.x' => ['required', 'numeric', 'between:0,1'],
            'edit.hidden.*.y' => ['required', 'numeric', 'between:0,1'],
            'edit.hidden.*.width' => ['required', 'numeric', 'between:0,1'],
            'edit.hidden.*.height' => ['required', 'numeric', 'between:0,1'],
            'edit.hidden.*.style' => ['required', 'string', 'in:blur,pixelate,fill'],
            'edit.hidden.*.shape' => ['nullable', 'string', 'in:rect,rounded,circle'],
        ]);

        // The picture the editor started from, if it is a Drive picture this
        // person may see -- the edit is kept as made from it
        $original = filled($validated['source'] ?? null)
            ? DriveFile::query()->where('ref_id', $validated['source'])->first()
            : null;

        if ($original && Gate::denies('view', $original)) {
            $original = null;
        }

        $sameOwner = $original
            && $original->getAttribute($owner->ownerColumn()) === $owner->getKey();

        $file = DriveFile::store($request->file('file'), $owner, $sameOwner ? $original->folder : null, $request->user());
        $file->fill([
            'name' => ($original ? pathinfo($original->name, PATHINFO_FILENAME) : 'Photo').' (edited).'.$file->ext,
            'source_id' => $original?->id,
            'edit' => $original ? $this->edit($validated['edit'] ?? null) : null,
        ])->save();

        return response()->json(['file' => $file->card()], 201);
    }

    /**
     * Where to start editing a picture: the original it was made from, when
     * there is one this person may see, and the edit that made it.
     */
    public function original(DriveFile $file): JsonResponse
    {
        Gate::authorize('view', $file);

        $source = $file->source;
        $visible = $source && Gate::allows('view', $source);

        return response()->json([
            'file' => $file->card(),
            'source' => $visible ? $source->card() : null,
            'edit' => $visible ? $file->edit : null,
        ]);
    }

    /**
     * The edit as kept: numbers as numbers, whatever the form sent.
     *
     * @param  array<string, mixed>|null  $edit
     * @return array<string, mixed>|null
     */
    private function edit(?array $edit): ?array
    {
        if ($edit === null) {
            return null;
        }

        return [
            'crop' => array_map('floatval', array_intersect_key($edit['crop'], array_flip(['x', 'y', 'width', 'height']))),
            'rotate' => (int) $edit['rotate'],
            'flipX' => filter_var($edit['flipX'], FILTER_VALIDATE_BOOLEAN),
            'flipY' => filter_var($edit['flipY'], FILTER_VALIDATE_BOOLEAN),
            'brightness' => (int) $edit['brightness'],
            'contrast' => (int) $edit['contrast'],
            'saturation' => (int) $edit['saturation'],
            'warmth' => (int) $edit['warmth'],
            'vignette' => (int) $edit['vignette'],
            'angle' => (float) $edit['angle'],
            'shape' => $edit['shape'],
            'maxSide' => (int) $edit['maxSide'],
            'hidden' => array_map(fn (array $area) => [
                ...array_map('floatval', array_intersect_key($area, array_flip(['x', 'y', 'width', 'height']))),
                'style' => $area['style'],
                'shape' => $area['shape'] ?? 'rect',
            ], array_values($edit['hidden'] ?? [])),
        ];
    }
}
