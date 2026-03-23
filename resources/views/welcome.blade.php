<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dispatch — AI agents for your GitHub repos</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600|geist-mono:400" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
</head>
<body class="bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-[Instrument_Sans] antialiased">

    {{-- Nav --}}
    <nav class="max-w-3xl mx-auto px-6 py-4 flex items-center justify-between">
        <a href="/" class="text-lg font-semibold tracking-tight">Dispatch</a>
        <div class="flex items-center gap-4 text-sm">
            <a href="https://github.com/datashaman/dispatch" class="text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100 transition-colors duration-150" title="GitHub">
                <svg class="size-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"/></svg>
            </a>
            @unless(\App\Http\Middleware\LaunchLockdown::isActive(request()))
                @auth
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center px-3 py-1.5 rounded-md bg-indigo-500 text-white text-sm font-medium hover:bg-indigo-400 transition-colors duration-150">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="inline-flex items-center px-3 py-1.5 rounded-md bg-indigo-500 text-white text-sm font-medium hover:bg-indigo-400 transition-colors duration-150">Log in</a>
                @endauth
            @endunless
        </div>
    </nav>

    {{-- Header --}}
    <header class="max-w-3xl mx-auto px-6 pt-8 pb-6">
        <h1 class="text-3xl font-semibold tracking-tight leading-tight">GitHub Actions, but the actions are AI agents</h1>
        <p class="mt-3 text-base text-zinc-600 dark:text-zinc-400 max-w-xl">Define rules in YAML. Connect your repo. AI agents handle the rest — triaging issues, reviewing code, responding to discussions.</p>
        <div class="mt-5 flex items-center gap-3">
            @unless(\App\Http\Middleware\LaunchLockdown::isActive(request()))
                @auth
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center px-4 py-2 rounded-md bg-indigo-500 text-white text-sm font-medium hover:bg-indigo-400 transition-colors duration-150">Get Started</a>
                @else
                    <a href="{{ route('login') }}" class="inline-flex items-center px-4 py-2 rounded-md bg-indigo-500 text-white text-sm font-medium hover:bg-indigo-400 transition-colors duration-150">Get Started</a>
                @endauth
            @endunless
        </div>
    </header>

    {{-- dispatch.yml code block --}}
    <section class="max-w-3xl mx-auto px-6 py-8">
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900 overflow-hidden">
            <div class="px-4 py-2 border-b border-zinc-200 dark:border-zinc-800 text-xs font-[Geist_Mono] text-zinc-500">dispatch.yml</div>
            <pre class="px-4 py-4 text-sm font-[Geist_Mono] leading-relaxed overflow-x-auto"><code><span class="text-zinc-500"># dispatch.yml</span>
<span class="text-indigo-600 dark:text-indigo-400">rules</span><span class="text-zinc-700 dark:text-zinc-300">:</span>
  - <span class="text-indigo-600 dark:text-indigo-400">name</span><span class="text-zinc-700 dark:text-zinc-300">:</span> <span class="text-zinc-700 dark:text-zinc-300">triage-issues</span>
    <span class="text-indigo-600 dark:text-indigo-400">on</span><span class="text-zinc-700 dark:text-zinc-300">:</span> <span class="text-zinc-700 dark:text-zinc-300">issues.opened</span>
    <span class="text-indigo-600 dark:text-indigo-400">prompt</span><span class="text-zinc-700 dark:text-zinc-300">:</span> <span class="text-zinc-700 dark:text-zinc-300">|</span>
      <span class="text-zinc-700 dark:text-zinc-300">Read the issue title and body.</span>
      <span class="text-zinc-700 dark:text-zinc-300">Add appropriate labels and post</span>
      <span class="text-zinc-700 dark:text-zinc-300">a summary comment with next steps.</span></code></pre>
        </div>
    </section>

    {{-- How it works --}}
    <section class="max-w-3xl mx-auto px-6 py-8">
        <h2 class="text-xl font-semibold tracking-tight mb-6">How it works</h2>
        <div class="flex flex-col gap-4">
            <div class="flex items-start gap-4">
                <span class="font-[Geist_Mono] text-sm font-medium text-indigo-500 pt-0.5">01</span>
                <p class="text-base text-zinc-700 dark:text-zinc-300">Add <code class="font-[Geist_Mono] text-sm bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 rounded">dispatch.yml</code> to your repo</p>
            </div>
            <div class="flex items-start gap-4">
                <span class="font-[Geist_Mono] text-sm font-medium text-indigo-500 pt-0.5">02</span>
                <p class="text-base text-zinc-700 dark:text-zinc-300">Connect via GitHub App</p>
            </div>
            <div class="flex items-start gap-4">
                <span class="font-[Geist_Mono] text-sm font-medium text-indigo-500 pt-0.5">03</span>
                <p class="text-base text-zinc-700 dark:text-zinc-300">Agents do the work</p>
            </div>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="max-w-3xl mx-auto px-6 py-8">
        <p class="text-center text-sm text-zinc-500">Self-hosted. Open source. Your keys, your infrastructure.</p>
        <p class="text-center text-xs text-zinc-400 dark:text-zinc-600 mt-2">&copy; {{ date('Y') }} Datashaman. All rights reserved.</p>
    </footer>

</body>
</html>
