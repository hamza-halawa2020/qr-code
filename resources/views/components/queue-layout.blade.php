<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#103d36">
    <title>{{ $title ?? 'دورك | نظام الانتظار' }}</title>
    <link rel="manifest" href="/manifest.webmanifest">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="site-header"><a class="brand" href="{{ route('home') }}"><span class="brand-mark">د</span> دورك <small>انتظار أوضح. خدمة أسهل.</small></a><span class="header-label">نظام إدارة الانتظار</span></header>
    <main class="page-shell">
        @if($errors->any()) <div class="notice error" role="alert">{{ $errors->first() }}</div> @endif
        {{ $slot }}
    </main>
    <footer class="site-footer">دورك محفوظ، تابع رقمك من هنا ومن شاشات الفرع.</footer>
</body>
</html>
