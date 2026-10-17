<?php

it("n'utilise plus de classes Tailwind absentes du CSS de Filament pour la carte de bienvenue", function () {
    $view = (string) file_get_contents(resource_path('views/filament/agent/pages/agent-dashboard.blade.php'));

    expect($view)->not->toContain('bg-gradient-to-r')
        ->and($view)->toContain('linear-gradient(90deg,#10b981,#0d9488)');
});
