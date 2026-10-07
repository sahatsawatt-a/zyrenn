<?php

namespace App\Support\Board;

use RuntimeException;

/**
 * A change to a board's items that can't be made, said so the one asking can
 * put it right: an item that isn't there, an id used twice.
 */
class BoardItemProblem extends RuntimeException {}
