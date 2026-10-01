<?php

namespace App\Support\Chat;

use RuntimeException;

/**
 * A room was asked to answer before it was told how.
 */
class ChatNotReady extends RuntimeException {}
