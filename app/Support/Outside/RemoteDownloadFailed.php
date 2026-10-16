<?php

namespace App\Support\Outside;

use RuntimeException;

/**
 * A RemoteDownload that could not be made, with a message fit to show the
 * client that asked for it.
 */
class RemoteDownloadFailed extends RuntimeException {}
