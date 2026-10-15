<?php

namespace App\Support\Formula;

/**
 * A part asked for that a record does not have -- trigger.body.folder when
 * nothing called folder was sent -- or a part asked of something with none.
 * coalesce() takes it as empty.
 */
class MissingPart extends FormulaError {}
