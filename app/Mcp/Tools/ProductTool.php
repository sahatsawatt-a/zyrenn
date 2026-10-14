<?php

namespace App\Mcp\Tools;

use App\Models\Product\ProductElement;
use App\Models\Product\ProductRequest;
use App\Models\Product\Requirement;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

/**
 * Base for the product tools: a project's product as a tree of parts, the
 * requests made of it and the requirements worked out from them. Each is
 * named by its code -- CMP-3, CAP-12, RQ-4, REQ-9 -- as the page shows it.
 * Any member reads them; its owners and editors keep them.
 */
abstract class ProductTool extends UserTool
{
    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'project' => $schema->string()->max(255)->description('The project whose product this is, by ref_id or name (see list-projects).')->required(),
        ];
    }

    protected function targetProject(Request $request, bool $changes = false): Project
    {
        return $this->projectCalled($request, 'A product\'s parts, requests and requirements', $changes ? 'can change its product' : null);
    }

    /**
     * One part of the project's product, by its code.
     */
    protected function partCoded(Project $project, string $code, string $argument = 'code'): ProductElement
    {
        return $project->productElements()->where('code', mb_strtoupper(trim($code)))->first()
            ?? throw ValidationException::withMessages([$argument => "\"{$project->name}\" has no part {$code}. See get-product."]);
    }

    protected function requestCoded(Project $project, string $code, string $argument = 'code'): ProductRequest
    {
        return $project->productRequests()->where('code', mb_strtoupper(trim($code)))->first()
            ?? throw ValidationException::withMessages([$argument => "\"{$project->name}\" has no request {$code}. See list-product-requests."]);
    }

    protected function requirementCoded(Project $project, string $code, string $argument = 'code'): Requirement
    {
        return $project->requirements()->where('code', mb_strtoupper(trim($code)))->first()
            ?? throw ValidationException::withMessages([$argument => "\"{$project->name}\" has no requirement {$code}. See list-requirements."]);
    }

    /**
     * A requirement as the tools answer with it.
     *
     * @return array<string, mixed>
     */
    protected function requirementShown(Requirement $requirement): array
    {
        $requirement->loadMissing(['elements:id,code', 'tickets:id,number', 'request:id,code', 'changes:id,code', 'owner:id,email']);
        $criteria = $requirement->criteria ?? [];

        return array_filter([
            'code' => $requirement->code,
            'title' => $requirement->title,
            'status' => $requirement->status,
            'kind' => $requirement->kind,
            'understanding' => $requirement->understanding,
            'priority' => $requirement->priority,
            'need' => $requirement->need,
            'body' => $requirement->body,
            'criteria' => $criteria,
            'met' => count($criteria) ? count(array_filter($criteria, fn (array $one) => $one['met'])).' of '.count($criteria) : null,
            'questions' => $requirement->questions,
            'assumptions' => $requirement->assumptions,
            'parts' => $requirement->elements->pluck('code')->all(),
            'tickets' => $requirement->tickets->pluck('number')->all(),
            'request' => $requirement->request?->code,
            'changes' => $requirement->changes?->code,
            'owner' => $requirement->owner?->email,
        ], fn ($value) => $value !== null && $value !== []);
    }

    protected function productUrl(Project $project): string
    {
        return route('projects.product.index', $project);
    }
}
