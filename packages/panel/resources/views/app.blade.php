<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="{{ $panelLocale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <meta property="csp-nonce" nonce="{{ $cspNonce }}">
@if ($panelBranded)
    <meta name="application-name" content="{{ $panelName }}">
@endif
    <title inertia>{{ $panelName }}</title>
@if ($panelFavicon === null)
    <link rel="icon" href="data:,">
@else
    <link rel="icon" href="{{ $panelFavicon }}" type="{{ $panelFaviconType }}">
@endif
    <script type="importmap" nonce="{{ $cspNonce }}">{!! $panelImportMap !!}</script>
@foreach ($panelStyles as $panelStyle)
    <link rel="stylesheet" href="{{ $panelStyle }}" nonce="{{ $cspNonce }}">
@endforeach
@if ($panelTheme !== null)
    <link rel="stylesheet" href="{{ $panelTheme }}" nonce="{{ $cspNonce }}">
@endif
@foreach ($panelAddonStyles as $panelAddonStyle)
    <link rel="stylesheet" href="{{ $panelAddonStyle }}" nonce="{{ $cspNonce }}">
@endforeach
@foreach ($panelPreloads as $panelPreload)
    <link rel="modulepreload" href="{{ $panelPreload }}" nonce="{{ $cspNonce }}">
@endforeach
@foreach ($panelDevClients as $panelDevClient)
    <script type="module" src="{{ $panelDevClient }}"></script>
@endforeach
    <script type="module" src="{{ $panelScript }}" nonce="{{ $cspNonce }}"></script>
</head>
<body>
    @inertia
</body>
</html>
