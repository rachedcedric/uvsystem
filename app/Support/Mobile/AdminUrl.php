<?php

namespace App\Support\Mobile;

use Illuminate\Support\Facades\Route;

/**
 * Traduit un lien de l'interface complète (ex. celui d'une notification de
 * rappel de dette) vers l'écran mobile équivalent, quand il existe.
 */
class AdminUrl
{
    public static function toMobile(string $url): string
    {
        $parts = parse_url($url);
        $path = rtrim($parts['path'] ?? '', '/');
        parse_str($parts['query'] ?? '', $query);

        $mobile = match (true) {
            $path === '/admin/remboursements/create' && isset($query['agent_id']) => self::route('m.remboursements.create', ['agent' => (int) $query['agent_id']]),
            (bool) preg_match('#^/admin/dettes/(\d+)$#', $path, $m) => self::route('m.dettes.agent', [(int) $m[1]]),
            $path === '/admin/dettes' => self::route('m.dettes'),
            (bool) preg_match('#^/admin/agents/(\d+)$#', $path, $m) => self::route('m.agents.show', [(int) $m[1]]),
            $path === '/admin/agents' => self::route('m.agents'),
            $path === '/admin/uv-transactions' => self::route('m.ventes'),
            $path === '/admin/remboursements' => self::route('m.remboursements'),
            $path === '/admin/commissions' => self::route('m.commissions'),
            $path === '/admin/dettes-fournisseurs' => self::route('m.dettes', ['v' => 'fournisseurs']),
            $path === '/admin' || $path === '' => self::route('m.home'),
            default => null,
        };

        return $mobile ?? $url;
    }

    private static function route(string $name, array $params = []): ?string
    {
        return Route::has($name) ? route($name, $params) : null;
    }
}
