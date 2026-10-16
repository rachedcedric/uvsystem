<?php

it('traduit les messages de validation et de connexion en français', function () {
    app()->setLocale('fr');

    expect(trans('validation.required', ['attribute' => trans('validation.attributes.code')]))
        ->toBe('Le champ code est obligatoire.')
        ->and(trans('auth.throttle', ['seconds' => 30]))->toContain('Trop de tentatives')
        ->and(trans('passwords.sent'))->toContain('e-mail');

    $validator = validator(['email' => 'pas-un-email'], ['email' => 'required|email', 'montant' => 'required']);

    expect($validator->errors()->first('email'))->toBe('Le champ adresse e-mail doit être une adresse e-mail valide.')
        ->and($validator->errors()->first('montant'))->toBe('Le champ montant est obligatoire.');
});
