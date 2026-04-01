<?php

use Multek\OneSignal\Messages\OneSignalMessage;

it('creates message with static factory', function () {
    $message = OneSignalMessage::create('Hello');

    expect($message->getBody())->toBe('Hello');
});

it('sets and gets body', function () {
    $message = (new OneSignalMessage)->body('Test body');

    expect($message->getBody())->toBe('Test body');
});

it('sets and gets heading', function () {
    $message = (new OneSignalMessage)->heading('Test heading');

    expect($message->getHeading())->toBe('Test heading');
});

it('sets and gets subtitle', function () {
    $message = (new OneSignalMessage)->subtitle('Test subtitle');

    expect($message->getSubtitle())->toBe('Test subtitle');
});

it('sets and gets url', function () {
    $message = (new OneSignalMessage)->url('https://example.com');

    expect($message->getUrl())->toBe('https://example.com');
});

it('sets and gets image', function () {
    $message = (new OneSignalMessage)->image('https://example.com/img.jpg');

    expect($message->getImage())->toBe('https://example.com/img.jpg');
});

it('sets and gets data', function () {
    $message = (new OneSignalMessage)->data(['key' => 'value']);

    expect($message->getData())->toBe(['key' => 'value']);
});

it('adds buttons', function () {
    $message = (new OneSignalMessage)
        ->button('btn1', 'Accept')
        ->button('btn2', 'Decline');

    expect($message->getButtons())->toHaveCount(2)
        ->and($message->getButtons()[0])->toBe(['id' => 'btn1', 'text' => 'Accept'])
        ->and($message->getButtons()[1])->toBe(['id' => 'btn2', 'text' => 'Decline']);
});

it('sets and gets template id', function () {
    $message = (new OneSignalMessage)->template('tpl-123');

    expect($message->getTemplateId())->toBe('tpl-123');
});

it('sets and gets priority', function () {
    $message = (new OneSignalMessage)->priority(10);

    expect($message->getPriority())->toBe(10);
});

it('sets and gets ttl', function () {
    $message = (new OneSignalMessage)->ttl(3600);

    expect($message->getTtl())->toBe(3600);
});

it('sets send after with DateTimeInterface', function () {
    $date = new DateTime('2026-01-01 12:00:00');
    $message = (new OneSignalMessage)->sendAfter($date);

    expect($message->getSendAfter())->toBe($date);
});

it('sets send after with string', function () {
    $message = (new OneSignalMessage)->sendAfter('2026-01-01 12:00:00');

    expect($message->getSendAfter())->toBeInstanceOf(DateTimeInterface::class);
});

it('sets and gets name', function () {
    $message = (new OneSignalMessage)->name('campaign-1');

    expect($message->getName())->toBe('campaign-1');
});

it('returns null for unset optional fields', function () {
    $message = new OneSignalMessage;

    expect($message->getHeading())->toBeNull()
        ->and($message->getSubtitle())->toBeNull()
        ->and($message->getUrl())->toBeNull()
        ->and($message->getImage())->toBeNull()
        ->and($message->getTemplateId())->toBeNull()
        ->and($message->getPriority())->toBeNull()
        ->and($message->getTtl())->toBeNull()
        ->and($message->getSendAfter())->toBeNull()
        ->and($message->getName())->toBeNull()
        ->and($message->getData())->toBe([])
        ->and($message->getButtons())->toBe([]);
});

it('supports fluent chaining', function () {
    $message = OneSignalMessage::create('Hello')
        ->heading('Title')
        ->subtitle('Sub')
        ->url('https://example.com')
        ->image('https://example.com/img.jpg')
        ->data(['key' => 'value'])
        ->button('btn1', 'OK')
        ->template('tpl-1')
        ->priority(10)
        ->ttl(3600)
        ->name('test');

    expect($message->getBody())->toBe('Hello')
        ->and($message->getHeading())->toBe('Title')
        ->and($message->getSubtitle())->toBe('Sub')
        ->and($message->getUrl())->toBe('https://example.com')
        ->and($message->getImage())->toBe('https://example.com/img.jpg')
        ->and($message->getData())->toBe(['key' => 'value'])
        ->and($message->getButtons())->toHaveCount(1)
        ->and($message->getTemplateId())->toBe('tpl-1')
        ->and($message->getPriority())->toBe(10)
        ->and($message->getTtl())->toBe(3600)
        ->and($message->getName())->toBe('test');
});
