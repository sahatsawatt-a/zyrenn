<?php

namespace App\Support\Formula;

use RuntimeException;

/**
 * A formula that can't be read or worked out, with what to fix, said the way
 * the person who wrote it would understand.
 */
class FormulaError extends RuntimeException {}
