<?php

use Multek\OneSignal\Builders\NotificationBuilder;
use Multek\OneSignal\OneSignalManager;
use onesignal\client\api\DefaultApi;

beforeEach(function () {
    $this->api = Mockery::mock(DefaultApi::class);
    $this->manager = new OneSignalManager($this->api, 'test-app-id');
    $this->builder = new NotificationBuilder($this->manager);
});

it('sets app id on notification', function () {
    $raw = $this->builder->raw();

    expect($raw->getAppId())->toBe('test-app-id');
});

it('targets a single user', function () {
    $this->builder->toUser('user_123');
    $raw = $this->builder->raw();

    expect($raw->getIncludeAliases())->toBe(['external_id' => ['user_123']])
        ->and($raw->getTargetChannel())->toBe('push');
});

it('targets multiple users', function () {
    $this->builder->toUsers(['user_1', 'user_2']);
    $raw = $this->builder->raw();

    expect($raw->getIncludeAliases())->toBe(['external_id' => ['user_1', 'user_2']]);
});

it('targets a segment', function () {
    $this->builder->toSegment('Active Users');
    $raw = $this->builder->raw();

    expect($raw->getIncludedSegments())->toBe(['Active Users']);
});

it('targets multiple segments', function () {
    $this->builder->toSegments(['Active Users', 'Premium']);
    $raw = $this->builder->raw();

    expect($raw->getIncludedSegments())->toBe(['Active Users', 'Premium']);
});

it('excludes segments', function () {
    $this->builder->excludeSegments(['Inactive']);
    $raw = $this->builder->raw();

    expect($raw->getExcludedSegments())->toBe(['Inactive']);
});

it('sets body content', function () {
    $this->builder->body('Hello world');
    $raw = $this->builder->raw();

    expect($raw->getContents()->getEn())->toBe('Hello world');
});

it('sets heading', function () {
    $this->builder->heading('My Title');
    $raw = $this->builder->raw();

    expect($raw->getHeadings()->getEn())->toBe('My Title');
});

it('sets subtitle', function () {
    $this->builder->subtitle('My Subtitle');
    $raw = $this->builder->raw();

    expect($raw->getSubtitle()->getEn())->toBe('My Subtitle');
});

it('sets image for android and ios', function () {
    $this->builder->image('https://example.com/img.jpg');
    $raw = $this->builder->raw();

    expect($raw->getBigPicture())->toBe('https://example.com/img.jpg')
        ->and($raw->getIosAttachments())->toBe(['image' => 'https://example.com/img.jpg']);
});

it('sets url', function () {
    $this->builder->url('https://example.com');
    $raw = $this->builder->raw();

    expect($raw->getUrl())->toBe('https://example.com');
});

it('sets custom data and merges', function () {
    $this->builder->data(['key1' => 'val1'])->data(['key2' => 'val2']);
    $raw = $this->builder->raw();

    expect($raw->getData())->toBe(['key1' => 'val1', 'key2' => 'val2']);
});

it('sets send after with string', function () {
    $this->builder->sendAfter('2026-01-01 12:00:00');
    $raw = $this->builder->raw();

    expect($raw->getSendAfter())->toBeInstanceOf(DateTimeInterface::class);
});

it('sets throttle rate', function () {
    $this->builder->throttle(100);
    $raw = $this->builder->raw();

    expect($raw->getThrottleRatePerMinute())->toBe(100);
});

it('adds buttons', function () {
    $this->builder->addButton('accept', 'Accept')->addButton('decline', 'Decline');
    $raw = $this->builder->raw();

    expect($raw->getButtons())->toHaveCount(2)
        ->and($raw->getButtons()[0])->toBe(['id' => 'accept', 'text' => 'Accept']);
});

it('sets priority', function () {
    $this->builder->priority(10);
    $raw = $this->builder->raw();

    expect($raw->getPriority())->toBe(10);
});

it('sets ttl', function () {
    $this->builder->ttl(3600);
    $raw = $this->builder->raw();

    expect($raw->getTtl())->toBe(3600);
});

it('sets template id', function () {
    $this->builder->template('tpl-abc');
    $raw = $this->builder->raw();

    expect($raw->getTemplateId())->toBe('tpl-abc');
});

it('sets name', function () {
    $this->builder->name('campaign-1');
    $raw = $this->builder->raw();

    expect($raw->getName())->toBe('campaign-1');
});

it('sets filters', function () {
    $filters = [['field' => 'tag', 'key' => 'plan', 'relation' => '=', 'value' => 'pro']];
    $this->builder->withFilters($filters);
    $raw = $this->builder->raw();

    expect($raw->getFilters())->toBe($filters);
});

it('returns fluent instance for chaining', function () {
    $result = $this->builder
        ->toUser('user_1')
        ->body('Hello')
        ->heading('Title')
        ->subtitle('Sub')
        ->image('https://img.jpg')
        ->url('https://example.com')
        ->data(['key' => 'val'])
        ->addButton('ok', 'OK')
        ->priority(10)
        ->ttl(60)
        ->template('tpl')
        ->name('test');

    expect($result)->toBeInstanceOf(NotificationBuilder::class);
});
