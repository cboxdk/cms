<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="{{ $panelLocale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <meta property="csp-nonce" nonce="{{ $cspNonce }}">
    <title inertia>Cbox CMS</title>
    <link rel="icon" href="data:,">
@foreach ($panelStyles as $panelStyle)
    <link rel="stylesheet" href="{{ $panelStyle }}" nonce="{{ $cspNonce }}">
@endforeach
@foreach ($panelPreloads as $panelPreload)
    <link rel="modulepreload" href="{{ $panelPreload }}" nonce="{{ $cspNonce }}">
@endforeach
    <script type="module" src="{{ $panelScript }}" nonce="{{ $cspNonce }}"></script>
</head>
<body>
    @inertia
</body>
</html>
