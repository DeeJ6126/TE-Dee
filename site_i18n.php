<?php
declare(strict_types=1);

if (!function_exists('site_lang')) {
    function site_lang(): string
    {
        return 'en';
    }
}

if (!function_exists('site_renderer')) {
    function site_renderer(): string
    {
        return 'g6';
    }
}

if (!function_exists('site_t')) {
    function site_t(array|string $messages, ?string $lang = null): string
    {
        if (is_string($messages)) {
            return $messages;
        }

        return (string)($messages['en'] ?? reset($messages) ?? '');
    }
}

if (!function_exists('site_url_with_lang')) {
    function site_url_with_lang(string $href, ?string $lang = null): string
    {
        return $href;
    }
}

if (!function_exists('site_url_with_state')) {
    function site_url_with_state(string $href, ?string $lang = null, ?string $renderer = null, array $extraParams = []): string
    {
        $parts = parse_url($href);
        $path = (string)($parts['path'] ?? $href);
        $params = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $params);
        }

        $params = array_merge($params, $extraParams);

        unset($params['lang'], $params['renderer']);

        $query = http_build_query($params);
        return $path . ($query !== '' ? '?' . $query : '');
    }
}
