<?php

declare(strict_types=1);

namespace VetBoard;

/**
 * Page arithmetic and links for every list in the system, so each page does not carry its own copy.
 * Display only: totals and bulk actions must always be worked out from the whole set, never from a slice.
 */
final class Paginator
{
    public const SIZES   = [10, 20, 50, 100];
    public const DEFAULT = 20;

    public readonly int $total;
    public readonly int $perPage;
    public readonly int $pages;
    public readonly int $page;
    public readonly int $offset;
    public readonly int $from;
    public readonly int $to;

    private string $pageKey;
    private string $sizeKey;
    /** @var array<string, mixed> */
    private array $query;

    /**
     * @param array<string, mixed> $query the request's query string (normally $_GET)
     */
    public function __construct(int $total, array $query, string $pageKey = 'page', string $sizeKey = 'per_page', int $default = self::DEFAULT)
    {
        $total   = max(0, $total);
        $perPage = (int) ($query[$sizeKey] ?? $default);
        if (!in_array($perPage, self::SIZES, true)) {
            $perPage = in_array($default, self::SIZES, true) ? $default : self::DEFAULT;
        }

        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min($pages, max(1, (int) ($query[$pageKey] ?? 1)));

        $this->total   = $total;
        $this->perPage = $perPage;
        $this->pages   = $pages;
        $this->page    = $page;
        $this->offset  = ($page - 1) * $perPage;
        $this->from    = $total === 0 ? 0 : $this->offset + 1;
        $this->to      = min($total, $this->offset + $perPage);
        $this->pageKey = $pageKey;
        $this->sizeKey = $sizeKey;
        $this->query   = $query;
    }

    /** The rows for the current page, from a list already held in memory. */
    public function slice(array $rows): array
    {
        return array_slice(array_values($rows), $this->offset, $this->perPage);
    }

    public function needed(): bool
    {
        return $this->total > min(self::SIZES);
    }

    /**
     * Page numbers to show, with null for a gap: 1 … 4 5 6 … 20.
     * @return list<int|null>
     */
    public function window(int $edge = 1, int $around = 1): array
    {
        $keep = [];
        for ($i = 1; $i <= $this->pages; $i++) {
            if ($i <= $edge || $i > $this->pages - $edge || abs($i - $this->page) <= $around) {
                $keep[] = $i;
            }
        }

        $out  = [];
        $prev = 0;
        foreach ($keep as $i) {
            if ($i - $prev > 1) {
                $out[] = $i - $prev === 2 ? $prev + 1 : null;
            }
            $out[] = $i;
            $prev  = $i;
        }

        return $out;
    }

    /** Link to a page that keeps every other filter in the address. */
    public function url(int $page, array $extra = []): string
    {
        $q = array_merge($this->query, $extra);
        unset($q[$this->pageKey]);
        if ($page > 1) {
            $q[$this->pageKey] = $page;
        }
        if (($q[$this->sizeKey] ?? null) !== null && (int) $q[$this->sizeKey] === self::DEFAULT) {
            unset($q[$this->sizeKey]);
        }

        $qs = http_build_query($q);

        return '?' . $qs;
    }

    /** Query values to carry in the "rows per page" form, without the page number or size. */
    public function carried(): array
    {
        $q = $this->query;
        unset($q[$this->pageKey], $q[$this->sizeKey]);

        return array_filter($q, static fn($v) => !is_array($v) && $v !== null && $v !== '');
    }

    public function sizeKey(): string
    {
        return $this->sizeKey;
    }
}
