<?php

namespace App\Support\Product;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * The codes a project's product is known by -- CMP-3, CAP-12, RQ-4, REQ-9 --
 * each counted up on its own and never given twice, even after what had one
 * is gone: a code in an old ticket or note always means what it meant.
 */
final class Codes
{
    public const COMPONENT = 'CMP';

    public const CAPABILITY = 'CAP';

    public const REQUEST = 'RQ';

    public const REQUIREMENT = 'REQ';

    public static function next(Project $project, string $prefix): string
    {
        return DB::transaction(function () use ($project, $prefix) {
            DB::table('product_counters')->insertOrIgnore(['project_id' => $project->id, 'prefix' => $prefix, 'last' => 0]);
            $last = (int) DB::table('product_counters')->where(['project_id' => $project->id, 'prefix' => $prefix])->lockForUpdate()->value('last') + 1;
            DB::table('product_counters')->where(['project_id' => $project->id, 'prefix' => $prefix])->update(['last' => $last]);

            return "{$prefix}-{$last}";
        });
    }
}
