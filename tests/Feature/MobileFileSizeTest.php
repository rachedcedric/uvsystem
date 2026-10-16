<?php

use App\Support\Mobile\FileSize;

it('affiche une taille de fichier lisible', function () {
    expect(FileSize::human(176))->toBe('176 o')
        ->and(FileSize::human(1024))->toBe('1 Ko')
        ->and(FileSize::human(250 * 1024))->toBe('250 Ko')
        ->and(FileSize::human((int) (2.5 * 1024 * 1024)))->toBe('2,5 Mo');
});
