<?php

namespace App\Models\Workflow;

use App\Models\Folder;
use Database\Factories\Workflow\WorkflowFolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowFolder extends Folder
{
    /** @use HasFactory<WorkflowFolderFactory> */
    use HasFactory;

    /**
     * @return HasMany<Workflow, $this>
     */
    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class, 'folder_id');
    }

    protected function itemModel(): string
    {
        return Workflow::class;
    }
}
