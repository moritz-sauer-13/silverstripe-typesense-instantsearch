<?php

namespace MoritzSauer\Instantsearch\Services;

use ElliotSawyer\SilverstripeTypesense\Typesense;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

class TypesenseScopedKeyService
{
    private const DEFAULT_TTL_SECONDS = 3600;
    private const DEFAULT_CACHE_TTL_SECONDS = 3300;
    private const EARLY_REFRESH_THRESHOLD_SECONDS = 120;

    /**
     * @return array{key: string, expires_at: int}
     */
    public function getScopedKeyPayload(?Member $member = null): array
    {
        $member = $member ?: Security::getCurrentUser();

        $cache = $this->getCache();
        $cacheKey = $this->buildCacheKey($member);

        $cached = $cache->get($cacheKey);
        if (is_array($cached)
            && isset($cached['key'], $cached['expires_at'])
            && ((int)$cached['expires_at'] - time()) > self::EARLY_REFRESH_THRESHOLD_SECONDS
        ) {
            return [
                'key' => (string)$cached['key'],
                'expires_at' => (int)$cached['expires_at'],
            ];
        }

        $payload = $this->generateScopedKeyPayload($member);
        $cache->set($cacheKey, $payload, $this->getCacheTtlSeconds());

        return $payload;
    }

    public function getScopedSearchKey(?Member $member = null): string
    {
        $payload = $this->getScopedKeyPayload($member);

        return $payload['key'];
    }

    private function generateScopedKeyPayload(?Member $member): array
    {
        $parentSearchKey = (string)Environment::getEnv('TYPESENSE_SEARCH_KEY');
        if ($parentSearchKey === '') {
            throw new RuntimeException('Missing TYPESENSE_SEARCH_KEY for scoped key generation');
        }

        /** @var SearchVisibilityService $visibility */
        $visibility = Injector::inst()->get(SearchVisibilityService::class);

        $expiresAt = time() + $this->getTtlSeconds();
        $parameters = [
            'filter_by' => $visibility->buildScopedFilter($member),
            'exclude_fields' => 'AccessibleTo',
            'expires_at' => $expiresAt,
        ];

        $scopedKey = Typesense::client()->keys->generateScopedSearchKey($parentSearchKey, $parameters);

        return [
            'key' => $scopedKey,
            'expires_at' => $expiresAt,
        ];
    }

    private function buildCacheKey(?Member $member): string
    {
        if (!$member || !$member->exists()) {
            return 'typesense-scoped-anonymous';
        }

        $groupIds = array_map('intval', $member->Groups()->column('ID'));
        sort($groupIds, SORT_NUMERIC);

        return sprintf(
            'typesense-scoped-member-%d-%s',
            $member->ID,
            sha1(implode(',', $groupIds))
        );
    }

    private function getTtlSeconds(): int
    {
        $ttl = (int)Environment::getEnv('TYPESENSE_SCOPED_KEY_TTL');

        return $ttl > 0 ? $ttl : self::DEFAULT_TTL_SECONDS;
    }

    private function getCacheTtlSeconds(): int
    {
        $ttl = (int)Environment::getEnv('TYPESENSE_SCOPED_KEY_CACHE_TTL');

        return $ttl > 0 ? $ttl : self::DEFAULT_CACHE_TTL_SECONDS;
    }

    private function getCache(): CacheInterface
    {
        return Injector::inst()->get(CacheInterface::class . '.TypesenseScopedKeyCache');
    }
}
