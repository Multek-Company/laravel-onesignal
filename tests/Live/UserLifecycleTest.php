<?php

use Multek\OneSignal\OneSignalManager;
use onesignal\client\model\Subscription;

it('runs the full user lifecycle against the real API', function () {
    $manager = app(OneSignalManager::class);
    $externalId = 'pest-live-'.bin2hex(random_bytes(6));
    $email = "pest-live-{$externalId}@example.com";
    $phone = '+15005550006';

    try {
        // create — tags + native properties + subscriptions in one call
        $manager->createUser($externalId, ['role' => 'tester'], [
            'language' => 'pt',
            'timezone_id' => 'America/Sao_Paulo',
        ], $email, $phone);

        // get — everything landed
        $user = $manager->getUser($externalId);
        $properties = $user->getProperties();
        expect($properties->getTags())->toMatchArray(['role' => 'tester'])
            ->and($properties->getLanguage())->toBe('pt')
            ->and($properties->getTimezoneId())->toBe('America/Sao_Paulo');

        $tokens = array_map(fn (Subscription $s) => $s->getToken(), $user->getSubscriptions() ?? []);
        expect($tokens)->toContain($email)->toContain($phone);

        // sync again — proves upsert: nothing duplicates
        $manager->createUser($externalId, ['role' => 'tester'], ['language' => 'pt'], $email, $phone);
        $again = $manager->getUser($externalId);
        $emailSubs = array_filter($again->getSubscriptions() ?? [], fn (Subscription $s) => $s->getToken() === $email);
        expect(count($emailSubs))->toBe(1, 'Re-sync duplicated the email subscription — upsert assumption broken, apply the spec contingency.');

        // update tags
        $manager->updateUserTags($externalId, ['role' => 'admin']);
        expect($manager->getUser($externalId)->getProperties()->getTags()['role'])->toBe('admin');

        // track event (Free plan: guard keeps this a no-op)
        $manager->trackEvent($externalId, 'live_test_event', ['source' => 'pest']);
    } finally {
        $manager->deleteUser($externalId);
    }
});
