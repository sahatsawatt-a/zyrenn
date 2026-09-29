<?php

namespace App\Mcp\Tools\Tables;

use RuntimeException;

/**
 * Something in a table tool's request that can't be written -- a column the
 * table doesn't have, a row that isn't there. Thrown inside the transaction so
 * nothing half-done is kept, and answered as an error the caller can fix.
 */
class TableProblem extends RuntimeException {}
