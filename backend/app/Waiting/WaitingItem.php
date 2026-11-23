<?php

namespace App\Waiting;

use Carbon\CarbonImmutable;

/**
 * One item in a user's "Waiting for me" list: what it is, its Site Snapshot, its current status, and when it
 * entered that status. Timeliness is PM-only and priority is E-Call-only; each is null for the other type.
 */
final readonly class WaitingItem
{
    public function __construct(
        public string $type,
        public int $id,
        public string $title,
        public string $siteId,
        public string $siteName,
        public string $status,
        public ?string $timeliness,
        public CarbonImmutable $waitingSince,
        public ?string $priority = null,
    ) {}

    /**
     * Oldest waiting first; ties broken by type, then ID.
     */
    public static function compare(self $left, self $right): int
    {
        return [$left->waitingSince->getTimestamp(), $left->waitingSince->micro, $left->type, $left->id]
            <=> [$right->waitingSince->getTimestamp(), $right->waitingSince->micro, $right->type, $right->id];
    }

    /**
     * @return array{type: string, id: int, title: string, site: array{id: string, name: string}, status: string, priority: ?string, timeliness: ?string, waiting_since: CarbonImmutable}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'title' => $this->title,
            'site' => ['id' => $this->siteId, 'name' => $this->siteName],
            'status' => $this->status,
            'priority' => $this->priority,
            'timeliness' => $this->timeliness,
            'waiting_since' => $this->waitingSince,
        ];
    }
}
