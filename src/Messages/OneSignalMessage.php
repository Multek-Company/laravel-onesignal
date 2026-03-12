<?php

namespace Multek\OneSignal\Messages;

class OneSignalMessage
{
    protected string $body = '';
    protected ?string $heading = null;
    protected ?string $subtitle = null;
    protected ?string $url = null;
    protected ?string $image = null;
    protected array $data = [];
    protected array $buttons = [];
    protected ?string $templateId = null;
    protected ?int $priority = null;
    protected ?int $ttl = null;
    protected ?\DateTimeInterface $sendAfter = null;
    protected ?string $name = null;

    public static function create(string $body = ''): static
    {
        $message = new static();
        $message->body = $body;

        return $message;
    }

    public function body(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function heading(string $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    public function getHeading(): ?string
    {
        return $this->heading;
    }

    public function subtitle(string $subtitle): static
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    public function getSubtitle(): ?string
    {
        return $this->subtitle;
    }

    public function url(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function image(string $url): static
    {
        $this->image = $url;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function data(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function button(string $id, string $text): static
    {
        $this->buttons[] = ['id' => $id, 'text' => $text];

        return $this;
    }

    public function getButtons(): array
    {
        return $this->buttons;
    }

    public function template(string $templateId): static
    {
        $this->templateId = $templateId;

        return $this;
    }

    public function getTemplateId(): ?string
    {
        return $this->templateId;
    }

    public function priority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function ttl(int $seconds): static
    {
        $this->ttl = $seconds;

        return $this;
    }

    public function getTtl(): ?int
    {
        return $this->ttl;
    }

    public function sendAfter(\DateTimeInterface|string $datetime): static
    {
        if (is_string($datetime)) {
            $datetime = new \DateTime($datetime);
        }

        $this->sendAfter = $datetime;

        return $this;
    }

    public function getSendAfter(): ?\DateTimeInterface
    {
        return $this->sendAfter;
    }

    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }
}
