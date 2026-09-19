<?php

declare(strict_types=1);

namespace JevTtt;

final readonly class JevResponse
{
    /**
     * @param int $status HTTP status, 0 when the request never completed
     * @param string $body raw response body, exactly as received
     * @param int|null $upstreamMs the x-envoy-upstream-service-time header: time spent behind TypeSafe's edge
     */
    public function __construct(
        public int $status,
        public string $body,
        public int $latencyMs,
        public ?int $upstreamMs,
        public ?string $requestId,
        public int $attempts,
        public ?string $error = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->status === 200;
    }

    /** @return array<string, mixed>|null */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
