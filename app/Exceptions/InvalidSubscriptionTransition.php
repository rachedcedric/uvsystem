<?php

namespace App\Exceptions;

use RuntimeException;

/** Opération d'abonnement impossible dans l'état actuel (ex. réactiver un abonnement non suspendu). */
class InvalidSubscriptionTransition extends RuntimeException {}
