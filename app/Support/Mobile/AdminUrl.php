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

        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            $path = '/master'.substr($path, strlen('/admin'));
        }

        $mobile = match (true) {
            $path === '/master/remboursements/create' && isset($query['agent_id']) => self::route('m.remboursements.create', ['agent' => (int) $query['agent_id']]),
            (bool) preg_match('#^/master/dettes/(\d+)$#', $path, $m) => self::route('m.dettes.agent', [(int) $m[1]]),
            $path === '/master/dettes' => self::route('m.dettes'),
            (bool) preg_match('#^/master/agents/(\d+)$#', $path, $m) => self::route('m.agents.show', [(int) $m[1]]),
            $path === '/master/agents' => self::route('m.agents'),
            $path === '/master/uv-transactions' => self::route('m.ventes'),
            $path === '/master/remboursements' => self::route('m.remboursements'),
            $path === '/master/commissions' => self::route('m.commissions'),
            $path === '/master/dettes-fournisseurs' => self::route('m.dettes', ['v' => 'fournisseurs']),
            $path === '/master' || $path === '' => self::route('m.home'),
            default => null,
        };

        return $mobile ?? $url;
    }

    private static function route(string $name, array $params = []): ?string
    {
        return Route::has($name) ? route($name, $params) : null;
    }
}
