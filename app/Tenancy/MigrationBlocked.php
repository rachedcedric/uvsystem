<?php

namespace App\Tenancy;

use RuntimeException;

/** La migration multi-tenant est refusée par le contrôle préalable : rien n'a été modifié. */
class MigrationBlocked extends RuntimeException {}
