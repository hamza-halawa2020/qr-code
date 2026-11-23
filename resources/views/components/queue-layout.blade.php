@php $locale = app()->getLocale(); $isRtl = $locale === 'ar'; @endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#103d36">
    <title>{{ $title ?? __('queue.site_title') }}</title>
    <link rel="manifest" href="/manifest.webmanifest">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="site-header">
        <a class="brand" href="{{ route('home') }}">
            <span class="brand-mark">{{ substr(__('queue.brand_name'), 0, 1) }}</span>
            {{ __('queue.brand_name') }}
            <small>{{ __('queue.brand_tagline') }}</small>
        </a>
        <!-- <span class="header-label">{{ __('queue.header_label') }}</span> -->
        <a class="lang-switch"
           href="{{ request()->fullUrlWithQuery(['lang' => __('queue.lang_switch_code')]) }}"
           aria-label="{{ __('queue.lang_switch') }}">{{ __('queue.lang_switch') }}</a>
    </header>
    <main class="page-shell">
        @if($errors->any()) <div class="notice error" role="alert">{{ $errors->first() }}</div> @endif
        {{ $slot }}
    </main>
    <footer class="site-footer">{{ __('queue.footer_text') }}</footer>
</body>
</html>
