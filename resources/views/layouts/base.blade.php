<!doctype html>
<html lang="en" @isset($accent) style="--accent: {{ $accent }}" @endisset>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>@yield('title', config('app.name'))</title>

    @hasSection('description')
        <meta name="description" content="@yield('description')">
    @endif

    @unless ($indexable ?? false)
        <meta name="robots" content="noindex, nofollow">
    @endunless

    @stack('head')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@yield('body')
</body>
</html>
