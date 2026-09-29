<?php

namespace Database\Factories\Table;

use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Columns are made through TableStorage, which also adds them to the rows; this
 * is for the metadata alone.
 *
 * @extends Factory<TableColumn>
 */
class TableColumnFactory extends Factory
{
    protected $model = TableColumn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'table_id' => Table::factory(),
            'name' => 'column_'.$this->faker->unique()->numberBetween(1, 99999),
            'label' => $this->faker->word(),
            'type' => 'varchar',
        ];
    }
}
