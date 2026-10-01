<?php

namespace App\Support\Chat;

use RuntimeException;

/**
 * A model could not be reached or would not answer. The message is fit to
 * show the user: it names what went wrong, never what the host sent back.
 */
class AiFailed extends RuntimeException {}
