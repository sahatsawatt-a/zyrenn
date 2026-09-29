<?php

namespace Database\Seeders;

use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Models\User;
use App\Support\Table\TableStorage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A sample table to look around in: a small CRM of leads, with a column of
 * nearly every kind. It is made the way the app makes one, so its storage and
 * its column names are exactly what a table made by hand would have.
 */
class TableSystemSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'test@example.com')->first() ?? User::firstOrFail();

        $folder = $user->tableFolders()->create(['name' => 'Enterprise CRM']);

        $table = $user->tables()->make(['title' => 'Global leads tracker']);
        $table->folder_id = $folder->id;
        $table->save();
        TableStorage::create($table);

        $status = [
            ['id' => '1', 'value' => 'Lead', 'color' => 'amber'],
            ['id' => '2', 'value' => 'In Progress', 'color' => 'sky'],
            ['id' => '3', 'value' => 'Proposal', 'color' => 'purple'],
            ['id' => '4', 'value' => 'Closed', 'color' => 'emerald'],
            ['id' => '5', 'value' => 'On Hold', 'color' => 'slate'],
        ];

        $tags = [
            ['id' => 'a', 'value' => 'Enterprise', 'color' => 'fuchsia'],
            ['id' => 'b', 'value' => 'SaaS', 'color' => 'indigo'],
            ['id' => 'c', 'value' => 'Priority', 'color' => 'rose'],
            ['id' => 'd', 'value' => 'Fintech', 'color' => 'cyan'],
            ['id' => 'e', 'value' => 'AI / ML', 'color' => 'emerald'],
        ];

        /** @var array<string, TableColumn> $columns keyed by the label, since the name is made from it */
        $columns = collect([
            ['label' => 'Full name', 'type' => 'varchar', 'width' => 170],
            ['label' => 'Status', 'type' => 'select', 'width' => 150, 'options_meta' => ['options' => $status]],
            ['label' => 'Tags', 'type' => 'multi_select', 'width' => 210, 'options_meta' => ['options' => $tags]],
            ['label' => 'Deal value', 'type' => 'currency', 'width' => 140, 'options_meta' => ['currencySymbol' => '$']],
            ['label' => 'Win %', 'type' => 'percent', 'width' => 120],
            ['label' => 'Priority', 'type' => 'rating', 'width' => 130, 'options_meta' => ['maxRating' => 5]],
            ['label' => 'Target close', 'type' => 'date', 'width' => 140],
            ['label' => 'Notes', 'type' => 'text', 'width' => 220],
            ['label' => 'Active', 'type' => 'boolean', 'width' => 90],
        ])->mapWithKeys(fn (array $column) => [
            $column['label'] => TableStorage::newColumn($table, $column),
        ])->all();

        $leads = [
            ['Alex Mercer', 'Lead', ['Enterprise', 'Priority'], 45000, 75, 4, '2026-10-15', 'Key decision maker for the Q4 roll-out. Next demo on Thursday.', true],
            ['Sarah Connor', 'Closed', ['SaaS'], 120000, 100, 5, '2026-09-30', 'Annual renewal signed, with the multi-seat security upgrade.', true],
            ['Miles Morales', 'In Progress', ['Fintech'], 28500, 50, 3, '2026-11-20', 'Trying the sandbox API and webhook reliability in staging.', true],
            ['Elena Rostova', 'Proposal', ['AI / ML', 'Enterprise'], 85000, 65, 5, '2026-12-05', 'Inference pipeline proposal sent. Legal review under way.', false],
            ['David Chen', 'Lead', ['SaaS', 'Priority'], 52000, 35, 2, '2026-10-31', 'First demo done. Asked for a follow-up with their architect.', true],
        ];

        foreach ($leads as $lead) {
            $row = ['created_at' => now(), 'updated_at' => now()];

            foreach (array_values($columns) as $index => $column) {
                $row[$column->name] = TableStorage::toStored($column, $lead[$index]);
            }

            DB::table(TableStorage::physicalName($table))->insert($row);
        }
    }
}
