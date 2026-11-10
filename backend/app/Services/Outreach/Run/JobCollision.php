<?php

namespace App\Services\Outreach\Run;

use RuntimeException;

/** La persona ya tiene un trabajo vivo (`ojob_one_live`) o ese intento ya existe (`ojob_attempt_unique`): lo decidió la base. */
final class JobCollision extends RuntimeException {}
